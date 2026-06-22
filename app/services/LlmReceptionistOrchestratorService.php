<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/SessionAuth.php';
require_once __DIR__ . '/ReceptionistStateManager.php';
require_once __DIR__ . '/ReceptionistToolService.php';
require_once __DIR__ . '/ReceptionistSafetyGuard.php';
require_once __DIR__ . '/LlmReceptionistResponseBuilder.php';
require_once __DIR__ . '/SymptomCheckerService.php';

class LlmReceptionistOrchestratorService
{
    private ReceptionistStateManager $stateManager;
    private ReceptionistToolService $tools;
    private ReceptionistSafetyGuard $safety;
    private LlmReceptionistResponseBuilder $replyBuilder;
    private SymptomCheckerService $symptomChecker;

    public function __construct()
    {
        $this->stateManager    = new ReceptionistStateManager();
        $this->tools           = new ReceptionistToolService();
        $this->safety          = new ReceptionistSafetyGuard();
        $this->replyBuilder    = new LlmReceptionistResponseBuilder();
        $this->symptomChecker  = new SymptomCheckerService();
    }

    public function syncClientSession(?string $clientPageId): void
    {
        $this->stateManager->syncClientPage($clientPageId);
    }

    public function reply(string $message): array
    {
        $message = trim($message);
        $patient = SessionAuth::patient();

        if ($this->isResetRequest($message)) {
            $this->stateManager->reset();
            return $this->plainResponse('reset', [], 'تمام، بدأت محادثة جديدة. كيف بقدر أساعدك؟');
        }

        $state = $this->stateManager->state();
        $state = $this->stateManager->appendTurn($state, 'user', $message);

        $safety = $this->safety->assess($message);
        if (($safety['level'] ?? 'normal') === 'emergency') {
            $state['goal'] = 'medical_safety';
            $state['intent'] = 'medical_emergency';
            return $this->finish($state, 'medical_emergency', ['safety' => $safety], (string) $safety['draft_reply'], $message, false);
        }

        if ($this->shouldHandleRecentBookedAppointmentChange($message, $state)) {
            return $this->handleRecentBookedAppointmentChange($message, $state, $patient);
        }

        // ── inquiry checks أولاً قبل أي appointment action ──────────────────
        // لو المريض يرد على قائمة مواعيده بـ "اعدل/الغي + رقم" أو رقم موعد مجرد
        if (($state['inquiry_shown'] ?? false) === true) {
            $normalized = $this->tools->normalize($message);
            $hasAction = $this->containsAny($normalized, ['اعدل', 'اغير', 'تعديل', 'الغ', 'الغي', 'عدل', 'غير', 'بدي اعدل', 'بدي اغير', 'بدي الغي', 'تغيير']);
            $apptId = $this->tools->extractAppointmentId($message);
            if ($apptId === null && preg_match('/^\s*#?\s*(\d{1,6})\s*$/', trim($message), $rawM)) {
                $apptId = (int) $rawM[1];
            }
            if ($hasAction || $apptId !== null) {
                $state['inquiry_shown'] = false;
                $effectivePatient = $patient ?? (is_array($state['inquiry_patient'] ?? null) ? $state['inquiry_patient'] : null);
                if (!$hasAction && $apptId !== null) {
                    $state['inquiry_pending_appt_id'] = $apptId;
                    $state['inquiry_patient'] = $effectivePatient;
                    return $this->finish($state, 'appointment_inquiry_action_choice', ['quick_replies' => ['تعديل الموعد', 'إلغاء الموعد']], "شو بدك تعمل بالموعد رقم #{$apptId}؟", $message);
                }
                $enriched = $message . ' موعد';
                return $this->handleAppointmentAction($enriched, $state, $effectivePatient);
            }
        }

        // لو كان عنده موعد معلّق ينتظر اختيار تعديل أو إلغاء
        if (isset($state['inquiry_pending_appt_id'])) {
            $normalized = $this->tools->normalize($message);
            $pendingId = (int) $state['inquiry_pending_appt_id'];
            $effectivePatient = $patient ?? (is_array($state['inquiry_patient'] ?? null) ? $state['inquiry_patient'] : null);
            unset($state['inquiry_pending_appt_id']);
            $actionType = $this->containsAny($normalized, ['الغ', 'الغاء', 'الغي']) ? 'cancel' : 'reschedule';
            $enriched = ($actionType === 'cancel' ? 'الغي' : 'اعدل') . " موعد رقم {$pendingId}";
            return $this->handleAppointmentAction($enriched, $state, $effectivePatient);
        }
        // ─────────────────────────────────────────────────────────────────────

        $appointmentAction = $state['appointment_action'] ?? [];
        if (!(($state['booking']['active'] ?? false) === true)
            && (($appointmentAction['active'] ?? false) === true || $this->tools->isExistingAppointmentChangeRequest($message))) {
            return $this->handleAppointmentAction($message, $state, $patient);
        }


        // لو الشات ينتظر رقم هوية للاستفسار عن المواعيد
        if (($state['inquiry_waiting_nid'] ?? false) === true) {
            $nid = $this->tools->extractNationalId($message);
            if ($nid !== null) {
                $found = $this->tools->findPatientByNationalId($nid);
                if ($found !== null) {
                    return $this->buildAppointmentInquiryReply($state, $found, $message);
                }
                return $this->finish($state, 'appointment_inquiry_not_found', ['quick_replies' => []], 'ما لقيت مريضاً بهذا الرقم. تأكد من الرقم وأرسله مرة ثانية.', $message);
            }
            // لو ما أرسل رقم، ذكّره
            return $this->finish($state, 'appointment_inquiry_need_nid', ['quick_replies' => []], 'أرسللي رقم هويتك (أرقام فقط) حتى أجيب مواعيدك.', $message);
        }

        $booking = $state['booking'] ?? [];
        $labResult = $state['lab_result'] ?? [];

        // Active booking takes priority over greeting/smalltalk — "اه" / "نعم" would otherwise
        // be classified as smalltalk and the LLM would hallucinate a booking confirmation.
        if (($booking['active'] ?? false) === true) {
            return $this->handleBooking($message, $state, $patient, $safety);
        }

        if ($this->isGreeting($message)) {
            return $this->finish($state, 'greeting', ['quick_replies' => []], 'أهلا وسهلا. أنا معك من استقبال مستشفى الأهلي. كيف بقدر أساعدك اليوم؟', $message);
        }

        if ($this->isSmalltalk($message)) {
            return $this->finish($state, 'smalltalk', ['quick_replies' => []], 'الحمد لله بخير، تسلم. احكيلي كيف بقدر أساعدك اليوم: حجز موعد، نتائج فحوصات، طبيب، قسم، أو خدمة؟', $message);
        }

        if (($labResult['active'] ?? false) === true) {
            return $this->handleLabResultQuestion($message, $state, $patient);
        }

        // أسئلة المعرفة العامة لها أولوية حتى أثناء الحجز — لكن ليس لو كانت سؤال عن طبيب
        if ($this->tools->isHospitalKnowledgeQuestion($message)
            && !$this->tools->isBookingRequest($message)
            && !$this->tools->isDoctorQuestion($message)
            && !$this->tools->isDoctorBioQuestion($message)) {
            return $this->handleGeneral($message, $state);
        }

        if (($booking['active'] ?? false) !== true) {
            if ($this->tools->isChatbotCapabilityQuestion($message)) {
                return $this->handleCapabilityQuestion($state, $message);
            }

            if ($this->tools->isAppointmentInquiry($message)) {
                return $this->handleAppointmentInquiry($state, $patient, $message);
            }

            if ($this->tools->isBookingRequest($message)) {
                // لو الرسالة فيها حجز + سؤال عن نتيجة تحليل، نذكّر المريض بالنتيجة أولاً
                if ($this->tools->isLabResultQuestion($message) && $patient !== null) {
                    $labNote = $this->tools->getLabResultSummary($patient['patient_id'] ?? 0);
                    $state['pending_lab_note'] = $labNote;
                }
                return $this->handleBooking($message, $state, $patient, $safety);
            }

            if ($this->tools->isLabResultQuestion($message)) {
                return $this->handleLabResultQuestion($message, $state, $patient);
            }

            // Doctor bio/question check BEFORE hospital knowledge (to avoid RAG hijacking doctor queries)
            if ($this->tools->isDoctorBioQuestion($message)) {
                return $this->handleDoctorQuestion($message, $state);
            }

            if ($this->tools->isDoctorQuestion($message)) {
                return $this->handleDoctorQuestion($message, $state);
            }

            // إذا في أعراض + عدم يقين ("مش عارف وين اروح"، "ايش القسم") → symptom checker أولاً
            $normCheck = $this->tools->normalize($message);
            $hasUncertaintyInGeneral = $this->containsAny($normCheck, [
                'مش عارف', 'ما عارف', 'ما بعرف', 'ما ادري', 'مش ادري',
                'ايش القسم', 'وين اروح', 'شو اعمل', 'وين اروح',
            ]);
            if ($hasUncertaintyInGeneral && $this->symptomChecker->isSymptomMessage($message)) {
                $symClassification = $this->symptomChecker->classify($message);
                $symReply = $symClassification !== null ? $this->symptomChecker->buildReply($message) : null;
                if ($symReply !== null && $symClassification !== null) {
                    $deptName2 = $symClassification['dept_name'] ?? null;
                    if ($deptName2 !== null) {
                        $allDepts = $this->tools->getDepartments();
                        foreach ($allDepts as $d2) {
                            if (($d2['name'] ?? '') === $deptName2) {
                                $booking['selected_department'] = ['department_id' => $d2['department_id'], 'name' => $d2['name']];
                                break;
                            }
                        }
                        if (empty($booking['selected_department'])) {
                            $booking['selected_department'] = ['department_id' => null, 'name' => $deptName2];
                        }
                    }
                    $booking['active'] = true;
                    $state['booking'] = $booking;
                    return $this->finish($state, 'symptom_check', ['symptom_check' => true, 'quick_replies' => []], $symReply, $message);
                }
            }

            if ($this->tools->isHospitalKnowledgeQuestion($message)) {
                return $this->handleGeneral($message, $state);
            }

            if ($this->isDepartmentLocationQuestion($message)) {
                return $this->handleDepartmentQuestion($message, $state);
            }

            if ($this->tools->isServiceQuestion($message)) {
                return $this->handleServiceQuestion($message, $state);
            }

            if ($this->tools->isDepartmentQuestion($message)) {
                return $this->handleDepartmentQuestion($message, $state);
            }
        }

        if (($booking['active'] ?? false) === true || $this->tools->isBookingRequest($message)) {
            return $this->handleBooking($message, $state, $patient, $safety);
        }

        // ML symptom checker — يحفظ القسم وينشّط booking حتى "اه" بعدها تشتغل
        if ($this->symptomChecker->isSymptomMessage($message)) {
            $symClass2   = $this->symptomChecker->classify($message);
            $symptomReply = $symClass2 !== null ? $this->symptomChecker->buildReply($message) : null;
            if ($symptomReply !== null && $symClass2 !== null) {
                $booking['active'] = true;
                $deptName3 = $symClass2['dept_name'] ?? null;
                if ($deptName3 !== null && empty($booking['selected_department'])) {
                    $allDepts3 = $this->tools->getDepartments();
                    foreach ($allDepts3 as $d3) {
                        if (($d3['name'] ?? '') === $deptName3) {
                            $booking['selected_department'] = ['department_id' => $d3['department_id'], 'name' => $d3['name']];
                            break;
                        }
                    }
                    if (empty($booking['selected_department'])) {
                        $booking['selected_department'] = ['department_id' => null, 'name' => $deptName3];
                    }
                }
                $state['booking'] = $booking;
                return $this->finish($state, 'symptom_check', ['symptom_check' => true, 'quick_replies' => []], $symptomReply, $message);
            }
        }

        if (($safety['level'] ?? 'normal') === 'medical_general') {
            $draft = 'بقدر أساعدك بتوجيه عام فقط، بدون تشخيص. احكيلي الأعراض بشكل أوضح: من متى بدأت؟ وهل في حرارة، ألم قوي، أو مرض سابق؟';
            $state['goal'] = 'medical_guidance';
            $state['intent'] = 'medical_general';
            return $this->finish($state, 'medical_general', ['safety' => $safety], $draft, $message);
        }

        return $this->handleGeneral($message, $state);
    }

    private function handleBooking(string $message, array $state, ?array $patient, array $safety): array
    {
        $booking = array_replace_recursive($this->stateManager->defaultBooking(), $state['booking'] ?? []);
        $booking['active'] = true;
        $state['goal'] = 'booking';
        $state['intent'] = 'book_appointment';

        // فحص إلغاء الحجز في أي مرحلة (قبل OTP وبعده)
        $normForCancel = $this->tools->normalize($message);
        $isCancelBooking = ($this->containsAny($normForCancel, ['الغي', 'الغ', 'بطل', 'بطلت']) && $this->containsAny($normForCancel, ['حجز', 'الحجز', 'الموعد', 'موعد']))
            || $this->containsAny($normForCancel, ['ما بدي احجز', 'مش بدي احجز', 'بطل الحجز', 'الغ الحجز', 'الغيلي الحجز', 'الغي الحجز', 'انهي الحجز', 'لا بدي احجز', 'مش بدي موعد', 'ما بدي موعد']);
        if ($isCancelBooking) {
            $booking = $this->stateManager->defaultBooking();
            $state['booking'] = $booking;
            return $this->finish($state, 'booking_cancelled', ['quick_replies' => []], 'تمام، الغيت الحجز. كيف بقدر أساعدك؟', $message, false);
        }

        if (($booking['stage'] ?? '') === 'need_otp') {
            // السماح للمريض بإلغاء الحجز حتى لو كان في مرحلة OTP
            $normOtp = $this->tools->normalize($message);
            if ($this->containsAny($normOtp, ['الغي', 'الغ', 'بطل', 'بطلت', 'ما بدي', 'مش بدي', 'خلص', 'بدي الغي', 'لغي', 'توقف'])) {
                $booking = $this->stateManager->defaultBooking();
                $state['booking'] = $booking;
                return $this->finish($state, 'booking_cancelled', ['quick_replies' => []], 'تمام، الغيت الحجز. كيف بقدر أساعدك؟', $message, false);
            }
            $verification = $this->tools->verifyCode($message, $booking);
            if (($verification['ok'] ?? false) === true) {
                $booking['verification_status'] = 'verified';
                try {
                    $appointment = $this->tools->createAppointment($booking);
                    $confirmationMail = $this->tools->sendAppointmentConfirmation($booking, $appointment);
                    $booking['appointment'] = $appointment;
                    $booking['active'] = false;
                    $booking['stage'] = 'booked';
                    $booking['booked_at'] = time();
                    $state['booking'] = $booking;
                    $doctor = (string) ($appointment['doctor_name'] ?? $booking['selected_doctor']['full_name'] ?? 'الطبيب');
                    $datetime = (string) ($appointment['appointment_datetime'] ?? '');
                    $id = (string) ($appointment['appointment_id'] ?? '');
                    $draft = "تم تأكيد الكود وحجز الموعد مع {$doctor} بتاريخ {$datetime}. رقم الحجز: {$id}. يمكنك تعديل الموعد خلال ساعة من الآن إذا احتجت.";
                    return $this->finish($state, 'appointment_booked', ['appointment' => $appointment, 'confirmation_mail' => $confirmationMail, 'quick_replies' => []], $draft, $message, false);
                } catch (Throwable $e) {
                    $booking['stage'] = 'need_time';
                    $booking['verification_status'] = 'failed';
                    $state['booking'] = $booking;
                    $draft = 'وصل الكود صحيح، لكن ما قدرت أثبت الموعد لأن الوقت صار غير متاح أو البيانات ناقصة. احكيلي وقت ثاني مناسب لنفس التاريخ.';
                    return $this->finish($state, 'booking_create_failed', ['error' => $e->getMessage(), 'quick_replies' => []], $draft, $message, false);
                }
            }

            $booking['otp_attempts'] = (int) ($booking['otp_attempts'] ?? 0) + 1;
            $state['booking'] = $booking;
            $reason = (string) ($verification['reason'] ?? 'invalid');
            $draft = $reason === 'expired'
                ? 'انتهت صلاحية رمز التأكيد. اكتب الإيميل مرة ثانية حتى أرسل رمز جديد.'
                : 'الرمز مش مطابق. تأكد من آخر رمز وصلك واكتبه من 6 أرقام.';
            if ($reason === 'expired') {
                $booking['stage'] = 'need_email';
                $booking['verification_status'] = 'expired';
                $state['booking'] = $booking;
            }
            return $this->finish($state, 'booking_otp_invalid', ['reason' => $reason, 'quick_replies' => []], $draft, $message, false);
        }

        // لو المريض يقول "ما عارف وين" أو "مش عارف" مع أعراض، وجّهه للـ symptom checker أولاً
        $bookingStage = $booking['stage'] ?? 'idle';
        if (empty($booking['selected_doctor']) && in_array($bookingStage, ['', 'idle'], true)) {
            $norm = $this->tools->normalize($message);
            $hasUncertainty = $this->containsAny($norm, [
                'ما عارف', 'مش عارف', 'ما بعرف', 'ما اعرف', 'ما عارفه', 'مش عارفه',
                'ما بعرفش', 'ما ادري', 'مش ادري', 'بدون ما اعرف',
            ]);
            if ($hasUncertainty && $this->symptomChecker->isSymptomMessage($message)) {
                $classification = $this->symptomChecker->classify($message);
                $symptomReply   = $classification !== null ? $this->symptomChecker->buildReply($message) : null;
                if ($symptomReply !== null && $classification !== null) {
                    $booking['active'] = true;
                    // حفظ القسم المكتشف حتى لما يقول "اه" يبحث فيه بس
                    $deptName = $classification['dept_name'] ?? null;
                    if ($deptName !== null) {
                        $depts = $this->tools->getDepartments();
                        foreach ($depts as $d) {
                            if (($d['name'] ?? '') === $deptName) {
                                $booking['selected_department'] = [
                                    'department_id' => $d['department_id'],
                                    'name'          => $d['name'],
                                ];
                                break;
                            }
                        }
                        // fallback: save dept name even without ID for display
                        if (empty($booking['selected_department'])) {
                            $booking['selected_department'] = ['department_id' => null, 'name' => $deptName];
                        }
                    }
                    $state['booking'] = $booking;
                    return $this->finish($state, 'symptom_check', ['symptom_check' => true, 'quick_replies' => []], $symptomReply, $message);
                }
                // Classifier detected symptoms but couldn't determine department — ask for clarification
                // instead of falling through to searchDoctors which would produce wrong results
                $clarify = "وين بالزبط بتحس بالألم أو المشكلة؟ مثلاً: الرأس، البطن، الظهر، الركبة، الصدر؟\n"
                    . "لما تقلي المكان بقدر أوجهك للقسم الصح.";
                $state['booking'] = $booking;
                return $this->finish($state, 'symptom_clarify', ['quick_replies' => []], $clarify, $message, false);
            }
        }

        // ── Fix A: simple confirmation ("اه"/"نعم") after symptom dept set → show ALL dept doctors ──
        $normMsg = $this->tools->normalize($message);
        $deptIdFromSymptom = !empty($booking['selected_department']['department_id'])
            ? (int) $booking['selected_department']['department_id'] : 0;
        if ($deptIdFromSymptom > 0
            && empty($booking['selected_doctor'])
            && empty($booking['candidate_doctors'])
            && in_array($bookingStage, ['idle', '', 'need_doctor'], true)
            && $this->containsAny($normMsg, ['نعم', 'اه', 'اي', 'اكيد', 'تمام', 'موافق', 'اوك', 'yes', 'ok', 'يلا', 'عيش', 'حسنا', 'صح', 'طيب'])
            && mb_strlen(trim($message), 'UTF-8') <= 15) {
            $allDept = $this->tools->searchDoctors('', $deptIdFromSymptom);
            if (!empty($allDept)) {
                $booking['candidate_doctors'] = $allDept;
                $booking['stage'] = 'choose_doctor';
                $state['booking'] = $booking;
                $next = $this->nextBookingStep($booking, $message);
                $state['booking'] = $next['booking'];
                return $this->finish($state, (string) $next['intent'], (array) ($next['data'] ?? ['quick_replies' => []]), (string) $next['draft'], $message, false);
            }
        }

        // ── Fix B: "مين في كمان / دكاتره ثانيين" → show more doctors from same dept ──
        $isMoreDoctors = $this->containsAny($normMsg, [
            'كمان دكاتره', 'دكاتره ثانيين', 'دكاتره تانيين', 'في كمان دكاتره',
            'في كمان دكتور', 'غير الدكتور', 'دكتور ثاني', 'غيرلي الدكتور',
            'شوفلي دكتور', 'فيه غير', 'مين غير',
            'مين الدكاتره', 'دكاتره القسم', 'دكاتره يلي', 'اطباء القسم',
            'شوفلي دكاتره', 'عندكم دكاتره', 'اسماء الدكاتره',
        ]);
        if ($isMoreDoctors) {
            $filterDeptId = !empty($booking['selected_department']['department_id'])
                ? (int) $booking['selected_department']['department_id'] : null;
            $moreDoctors = $this->tools->searchDoctors('', $filterDeptId);
            if (!empty($moreDoctors)) {
                $booking['selected_doctor'] = null;
                $booking['candidate_doctors'] = $moreDoctors;
                $booking['stage'] = 'choose_doctor';
                $state['booking'] = $booking;
                $next = $this->nextBookingStep($booking, $message);
                $state['booking'] = $next['booking'];
                return $this->finish($state, (string) $next['intent'], (array) ($next['data'] ?? ['quick_replies' => []]), (string) $next['draft'], $message, false);
            }
        }

        // سؤال بايو/تخصص دكتور قبل اختياره → أجب وحدد الدكتور تلقائياً إذا كان واضحاً
        if (empty($booking['selected_doctor']) && $this->tools->isDoctorBioQuestion($message)) {
            $deptId = !empty($booking['selected_department']['department_id'])
                ? (int) $booking['selected_department']['department_id'] : null;
            $bioDoctors = $this->tools->searchDoctors($message, $deptId);
            if (!empty($bioDoctors)) {
                $bio = $this->tools->doctorBiography($bioDoctors[0]);
                if (count($bioDoctors) === 1) {
                    $booking['selected_doctor'] = $bioDoctors[0];
                    $booking['selected_department'] = [
                        'department_id' => $bioDoctors[0]['department_id'] ?? null,
                        'name'          => $bioDoctors[0]['department_name'] ?? null,
                    ];
                    $booking['candidate_doctors'] = [];
                }
                $state['booking'] = $booking;
                $draft = $bio . "\n" . $this->currentBookingQuestion($booking);
                return $this->finish($state, 'booking_side_question', ['booking' => $this->publicBooking($booking), 'quick_replies' => []], $draft, $message, false);
            }
        }

        $booking = $this->applyBookingUpdates($message, $booking);

        // When no doctor selected yet and user asks about departments, answer and keep booking alive
        // But skip if candidate_doctors already populated (user asked about doctors IN a dept)
        if (empty($booking['selected_doctor']) && empty($booking['candidate_doctors'])
            && $this->tools->isDepartmentQuestion($message)
            && !$this->tools->isBookingRequest($message)
            && !$this->tools->isDoctorQuestion($message)) {
            $departments = $this->tools->getDepartments();
            $deptLines = [];
            $dIdx = 1;
            foreach ($departments as $d) {
                $dName = (string) ($d['name'] ?? '');
                if ($dName !== '') {
                    $deptLines[] = "{$dIdx}. {$dName}";
                    $dIdx++;
                }
            }
            $draft = "أقسام المستشفى:\n" . implode("\n", $deptLines) . "\n\nأي قسم بدك؟ قلي اسمه وأعرضلك أطباءه وأكمل الحجز معك.";
            $state['booking'] = $booking;
            return $this->finish($state, 'booking_department_query', ['departments' => $departments, 'quick_replies' => []], $draft, $message, true);
        }

        if ($this->isBookingSideQuestion($message, $booking)) {
            $side = $this->answerSideQuestion($message, $patient);
            if ($side !== null) {
                $state['booking'] = $booking;
                $draft = $side . "\n\n" . $this->continuationReminder($booking);
                return $this->finish($state, 'booking_side_question', ['booking' => $this->publicBooking($booking), 'quick_replies' => []], $draft, $message, false);
            }
        }

        $next = $this->nextBookingStep($booking, $message);
        $state['booking'] = $next['booking'];

        // لو الرسالة الأولى فيها سؤال عن تحليل، أضفه قبل رد الحجز
        $draft = (string) $next['draft'];
        if (!empty($state['pending_lab_note']) && ($booking['stage'] ?? '') === '') {
            $draft = $state['pending_lab_note'] . "\n\n" . $draft;
            unset($state['pending_lab_note']);
        }

        return $this->finish(
            $state,
            (string) $next['intent'],
            (array) ($next['data'] ?? ['quick_replies' => []]),
            $draft,
            $message,
            false
        );
    }

    private function applyBookingUpdates(string $message, array $booking): array
    {
        if ($this->tools->isChangeDoctorRequest($message)) {
            $booking['selected_doctor'] = null;
            $booking['candidate_doctors'] = [];
            $booking['selected_time'] = null;
            $booking['stage'] = 'need_doctor';
            // لو الرسالة بذكر قسم/تخصص جديد → امسح القسم القديم حتى يبحث في القسم الصح
            if ($this->messageMentionsDepartmentOrSpecialty($message)) {
                $booking['selected_department'] = null;
            }
        }
        if ($this->tools->isChangeDateRequest($message)) {
            $booking['selected_date'] = null;
            $booking['selected_time'] = null;
            $booking['stage'] = 'need_date';
        }
        if ($this->tools->isChangeTimeRequest($message)) {
            $booking['selected_time'] = null;
            $booking['stage'] = 'need_time';
        }

        $email = $this->tools->extractEmail($message);
        if ($email !== null) {
            $booking['patient_email'] = $email;
        }

        $phone = $this->tools->extractPhone($message);
        if ($phone !== null) {
            $booking['patient_phone'] = $phone;
        }

        // Don't overwrite national_id when we're explicitly collecting phone number
        if (($booking['stage'] ?? '') !== 'need_patient_phone') {
            $nationalId = $this->tools->extractNationalId($message);
            if ($nationalId !== null) {
                $booking['patient_national_id'] = $nationalId;
                $booking['verified_patient'] = null;
            }
        }

        if (($booking['stage'] ?? '') === 'need_patient_name' && empty($booking['patient_name'])) {
            $patientName = $this->extractPatientName($message);
            if ($patientName !== null) {
                $booking['patient_name'] = $patientName;
            }
        }

        $date = $this->tools->extractDate($message);
        if ($date !== null && $date !== ($booking['selected_date'] ?? null)) {
            $booking['selected_date'] = $date;
            $booking['selected_time'] = null;
        }

        $time = $this->tools->extractTime($message);
        if ($time !== null) {
            $booking['selected_time'] = $time;
        }

        if (empty($booking['selected_doctor'])) {
            $candidates = isset($booking['candidate_doctors']) && is_array($booking['candidate_doctors'])
                ? $booking['candidate_doctors']
                : [];

            // اختيار الدكتور برقم من القائمة (1، 2، 3...)
            $selectedByNum = null;
            if (!empty($candidates) && preg_match('/^\s*([1-6])\s*$/', trim($message), $numM)) {
                $numIdx = (int) $numM[1] - 1;
                if (isset($candidates[$numIdx])) {
                    $selectedByNum = $candidates[$numIdx];
                }
            }

            if ($selectedByNum !== null) {
                $matches = [$selectedByNum];
            } elseif (!empty($candidates)) {
                $matches = $this->tools->matchDoctorFromCandidates($message, $candidates);
            } else {
                $matches = $this->tools->searchDoctors($message, isset($booking['selected_department']['department_id']) ? (int) $booking['selected_department']['department_id'] : null);
            }

            if (count($matches) === 1) {
                $booking['selected_doctor'] = $matches[0];
                $booking['selected_department'] = [
                    'department_id' => $matches[0]['department_id'] ?? null,
                    'name' => $matches[0]['department_name'] ?? null,
                ];
                $booking['candidate_doctors'] = [];
            } elseif (count($matches) > 1) {
                $booking['candidate_doctors'] = $matches;
                $booking['stage'] = 'choose_doctor';
            }
        }

        if (($booking['stage'] ?? '') === 'need_reason'
            && empty($booking['reason'])
            && !$this->isFirstVisitStatement($message)
            && $this->tools->extractEmail($message) === null
            && $this->tools->extractVerificationCode($message) === null
            && $this->tools->extractNationalId($message) === null
            && $this->tools->extractDate($message) === null
            && $this->tools->extractTime($message) === null) {
            $normalized = $this->tools->normalize($message);
            $booking['reason'] = str_contains($normalized, 'تخطي') || str_contains($normalized, 'بدون')
                ? 'حجز عبر الشات بوت'
                : trim($message);
        }

        return $booking;
    }

    private function nextBookingStep(array $booking, string $message): array
    {
        if (!empty($booking['candidate_doctors']) && empty($booking['selected_doctor'])) {
            $booking['stage'] = 'choose_doctor';
            $docLines = [];
            foreach (array_slice($booking['candidate_doctors'], 0, 6) as $i => $doc) {
                $num = $i + 1;
                $name = (string) ($doc['full_name'] ?? 'طبيب');
                $spec = (string) ($doc['specialty'] ?? '');
                $docLines[] = "{$num}. {$name}" . ($spec !== '' ? " — {$spec}" : '');
            }
            $docListText = implode("\n", $docLines);
            return [
                'booking' => $booking,
                'intent' => 'booking_choose_doctor',
                'data' => ['doctors' => $booking['candidate_doctors'], 'quick_replies' => []],
                'draft' => "لقيت أكثر من طبيب مناسب:\n{$docListText}\n\nأي طبيب تقصد؟ اكتب رقمه أو اسمه.",
            ];
        }

        if (empty($booking['selected_doctor'])) {
            $booking['stage'] = 'need_doctor';
            $booking['last_missing_field'] = 'doctor';
            return [
                'booking' => $booking,
                'intent' => 'booking_need_doctor',
                'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => 'تمام. لأي طبيب أو تخصص بدك أحجز الموعد؟',
            ];
        }

        if (empty($booking['selected_date'])) {
            $booking['stage'] = 'need_date';
            $booking['last_missing_field'] = 'date';
            $doctor = (string) ($booking['selected_doctor']['full_name'] ?? 'الطبيب');
            return [
                'booking' => $booking,
                'intent' => 'booking_need_date',
                'data' => ['doctor' => $booking['selected_doctor'], 'booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => "ممتاز، مع {$doctor}. أي يوم بناسبك؟ اكتب مثلا بكرا أو تاريخ مثل 2026-05-20.",
            ];
        }

        if (!empty($booking['selected_doctor']) && !empty($booking['selected_date'])) {
            // إذا عنا last_slots محفوظة وبس المريض اختار وقت → تحقق منه بدون ما نعيد الجلب
            $storedSlots = $booking['last_slots'] ?? [];
            if (!empty($booking['selected_time']) && !empty($storedSlots)) {
                if (!in_array($booking['selected_time'], $storedSlots, true)) {
                    $shown = implode('، ', array_slice($storedSlots, 0, 8));
                    $booking['selected_time'] = null;
                    $booking['stage'] = 'need_time';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_time_unavailable',
                        'data' => ['slots' => ['available_slots' => $storedSlots], 'quick_replies' => []],
                        'draft' => "الوقت اللي اخترته مش متاح. اختار من هاي الأوقات: {$shown}. أي وقت بناسبك؟",
                    ];
                }
                // الوقت صح → ما نحتاج نعيد الجلب
            } else {
                // جلب جديد للـ slots (عند تحديد التاريخ أو تغيير الفترة)
                try {
                    $availability = $this->tools->getDoctorAvailability(
                        (int) $booking['selected_doctor']['doctor_id'],
                        (string) $booking['selected_date'],
                        $this->tools->extractPeriod($message)
                    );
                    $booking['last_slots'] = $availability['available_slots'] ?? [];
                } catch (Throwable $e) {
                    $booking['selected_date'] = null;
                    $booking['selected_time'] = null;
                    $booking['stage'] = 'need_date';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_bad_date',
                        'data' => ['error' => $e->getMessage(), 'quick_replies' => []],
                        'draft' => 'التاريخ اللي كتبته مش مناسب أو في الماضي. اكتب تاريخ قادم بصيغة واضحة مثل 2026-05-20.',
                    ];
                }

                if (empty($booking['last_slots'])) {
                    $date = (string) $booking['selected_date'];
                    $doctor = (string) ($booking['selected_doctor']['full_name'] ?? 'الطبيب');
                    $booking['selected_time'] = null;
                    $booking['stage'] = 'need_date';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_no_slots',
                        'data' => ['doctor' => $booking['selected_doctor'], 'slots' => $availability ?? [], 'quick_replies' => []],
                        'draft' => "ما لقيت مواعيد متاحة عند {$doctor} بتاريخ {$date}. اكتب تاريخ ثاني وبفحصه لك.",
                    ];
                }

                // تحقق من الوقت إذا اتعيّن مع الـ slots الجديدة
                if (!empty($booking['selected_time']) && !in_array($booking['selected_time'], $booking['last_slots'], true)) {
                    $shown = implode('، ', array_slice($booking['last_slots'], 0, 8));
                    $booking['selected_time'] = null;
                    $booking['stage'] = 'need_time';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_time_unavailable',
                        'data' => ['slots' => $availability ?? [], 'quick_replies' => []],
                        'draft' => "الوقت اللي اخترته مش متاح. اختار من هاي الأوقات: {$shown}. أي وقت بناسبك؟",
                    ];
                }
            }
        }

        if (empty($booking['selected_time'])) {
            $booking['stage'] = 'need_time';
            $booking['last_missing_field'] = 'time';
            $slots = array_slice($booking['last_slots'] ?? [], 0, 6);
            $shown = implode('، ', $slots);
            $doctor = (string) ($booking['selected_doctor']['full_name'] ?? 'الطبيب');
            $date = (string) $booking['selected_date'];
            return [
                'booking' => $booking,
                'intent' => 'booking_choose_time',
                'data' => ['doctor' => $booking['selected_doctor'], 'slots' => $booking['last_slots'] ?? [], 'quick_replies' => $slots],
                'draft' => "الأوقات المتاحة مع {$doctor} بتاريخ {$date}: {$shown}. اختر الوقت المناسب.",
            ];
        }

        if (empty($booking['reason'])) {
            $booking['stage'] = 'need_reason';
            $booking['last_missing_field'] = 'reason';
            return [
                'booking' => $booking,
                'intent' => 'booking_need_reason',
                'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => 'بقي سبب الزيارة باختصار. اكتب مثلا مراجعة، فحص عام، أو اكتب تخطي.',
            ];
        }

        if (empty($booking['patient_national_id'])) {
            $lastField = $booking['last_missing_field'] ?? null;
            $booking['stage'] = 'need_national_id';
            $booking['last_missing_field'] = 'national_id';
            if ($lastField === 'national_id') {
                $nidError = $this->tools->diagnoseNationalIdError($message);
                $draft = $nidError ?? 'لم أتعرف على رقم هوية صحيح. اكتب رقم الهوية (7–12 رقم).';
            } else {
                $draft = 'قبل تثبيت الحجز، اكتب رقم الهوية. إذا أنت أول مرة تزورنا عادي، اكتب رقم الهوية وسأفتح لك ملف جديد أثناء الحجز.';
            }
            return [
                'booking' => $booking,
                'intent' => 'booking_need_national_id',
                'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => $draft,
            ];
        }

        if (empty($booking['verified_patient']) || !is_array($booking['verified_patient'])) {
            $verifiedPatient = $this->tools->findPatientByNationalId((string) $booking['patient_national_id']);
            if ($verifiedPatient === null) {
                $booking['new_patient_requested'] = true;

                if (empty($booking['patient_name'])) {
                    $booking['stage'] = 'need_patient_name';
                    $booking['last_missing_field'] = 'patient_name';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_need_patient_name',
                        'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                        'draft' => 'ما لقيت ملف مريض لهذا الرقم. رح أفتح لك ملف جديد الآن. اكتب الاسم الكامل للمريض.',
                    ];
                }

                if (empty($booking['patient_phone'])) {
                    $lastField = $booking['last_missing_field'] ?? null;
                    $booking['stage'] = 'need_patient_phone';
                    $booking['last_missing_field'] = 'patient_phone';
                    if ($lastField === 'patient_phone') {
                        $phoneError = $this->tools->diagnosePhoneError($message);
                        $draft = $phoneError ?? 'لم أتعرف على رقم هاتف صحيح. اكتب رقم الهاتف المكوّن من 10 أرقام (مثال: 0591234567).';
                    } else {
                        $draft = 'ممتاز. اكتب رقم هاتف المريض (مثال: 0591234567).';
                    }
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_need_patient_phone',
                        'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                        'draft' => $draft,
                    ];
                }

                if (empty($booking['patient_email'])) {
                    $lastField = $booking['last_missing_field'] ?? null;
                    $booking['stage'] = 'need_email';
                    $booking['last_missing_field'] = 'email';
                    if ($lastField === 'email') {
                        $emailError = $this->tools->diagnoseEmailError($message);
                        $draft = $emailError ?? 'لم أتعرف على إيميل صحيح. اكتب الإيميل بصيغة user@example.com.';
                    } else {
                        $draft = 'ممتاز. اكتب الإيميل حتى أرسل رمز تأكيد الحجز وأنشئ ملف المريض الجديد.';
                    }
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_need_email',
                        'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                        'draft' => $draft,
                    ];
                }

                try {
                    $booking['verified_patient'] = $this->tools->createOrGetPatient(
                        (string) $booking['patient_email'],
                        (string) ($booking['patient_name'] ?? ''),
                        (string) $booking['patient_national_id'],
                        (string) ($booking['patient_phone'] ?? '')
                    );
                } catch (Throwable) {
                    $booking['stage'] = 'need_email';
                    $booking['last_missing_field'] = 'email';
                    return [
                        'booking' => $booking,
                        'intent' => 'booking_patient_create_failed',
                        'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                        'draft' => 'ما قدرت أفتح ملف مريض جديد بهذه البيانات. تأكد من الإيميل ورقم الهوية، أو اكتب إيميل ثاني.',
                    ];
                }
            } else {
                $booking['verified_patient'] = $verifiedPatient;
                if (empty($booking['patient_email']) && !empty($verifiedPatient['email'])) {
                    $booking['patient_email'] = (string) $verifiedPatient['email'];
                }
            }
        }

        if (empty($booking['patient_email'])) {
            $lastField = $booking['last_missing_field'] ?? null;
            $booking['stage'] = 'need_email';
            $booking['last_missing_field'] = 'email';
            if ($lastField === 'email') {
                $emailError = $this->tools->diagnoseEmailError($message);
                $draft = $emailError ?? 'لم أتعرف على إيميل صحيح. اكتب الإيميل بصيغة user@example.com.';
            } else {
                $draft = 'آخر خطوة قبل التثبيت: اكتب إيميلك حتى أرسل رمز تأكيد الحجز.';
            }
            return [
                'booking' => $booking,
                'intent' => 'booking_need_email',
                'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => $draft,
            ];
        }

        if (($booking['verification_status'] ?? '') !== 'sent') {
            $otp = $this->tools->sendVerificationCode($booking);
            $booking['otp_hash'] = $otp['otp_hash'];
            $booking['otp_expires_at'] = $otp['otp_expires_at'];
            $booking['otp_attempts'] = 0;
            $booking['verification_status'] = 'sent';
            $booking['stage'] = 'need_otp';
            $masked = $this->maskEmail((string) $booking['patient_email']);
            $draft = "أرسلت رمز التأكيد على {$masked}. اكتب الرمز هون حتى أثبت الموعد.";

            return [
                'booking' => $booking,
                'intent' => 'booking_email_code_sent',
                'data' => ['email' => $masked, 'mail' => $otp['send_result'], 'booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => $draft,
            ];
        }

        $booking['stage'] = 'need_otp';
        return [
            'booking' => $booking,
            'intent' => 'booking_otp_needed',
            'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
            'draft' => 'اكتب رمز التأكيد المكوّن من 6 أرقام حتى أثبت الموعد.',
        ];
    }

    private function handleDepartmentQuestion(string $message, array $state): array
    {
        $state['goal'] = 'department_info';
        $state['intent'] = 'ask_departments';
        $normalized = $this->tools->normalize($message);

        $isGeneralListing = $this->containsAny($normalized, [
            'ايش الاقسام', 'شو الاقسام', 'كل الاقسام', 'جميع الاقسام',
            'اقسام المستشفى', 'اقسام عندكم', 'الاقسام الموجوده', 'الاقسام الموجودة',
            'عندكم اقسام', 'في اقسام', 'الاقسام في المستشفى', 'كم قسم',
            'شو الاقسام', 'ايش عندكم', 'شو عندكم', 'ايش في',
        ]);

        if ($isGeneralListing) {
            $departments = $this->tools->getDepartments();
        } else {
            $departments = $this->tools->searchDepartments($message);
            if (empty($departments)) {
                $departments = $this->tools->getDepartments();
            }
        }

        $lines = [];
        foreach ($departments as $i => $department) {
            $num = $i + 1;
            $name = (string) ($department['name'] ?? 'قسم');
            $location = (string) ($department['location'] ?? '');
            $hours = (string) ($department['working_hours'] ?? '');
            $line = "{$num}. {$name}";
            if ($location !== '') {
                $line .= " — {$location}";
            }
            if ($hours !== '') {
                $line .= " | الدوام: {$hours}";
            }
            $lines[] = $line;
        }

        if (empty($lines)) {
            $draft = 'ما لقيت قسم مطابق. اكتب اسم القسم أو التخصص اللي بدك إياه.';
        } elseif ($isGeneralListing) {
            $draft = 'أقسام مستشفى الأهلي (' . count($departments) . ' أقسام):' . "\n" . implode("\n", $lines);
        } else {
            $draft = count($lines) === 1
                ? implode("\n", $lines)
                : "المعلومات عن الأقسام:\n" . implode("\n", $lines);
        }

        return $this->finish($state, 'ask_departments', ['departments' => $departments, 'quick_replies' => []], $draft, $message);
    }

    private function handleDoctorQuestion(string $message, array $state): array
    {
        $state['goal'] = 'doctor_info';
        $state['intent'] = 'ask_doctors';

        // استخراج القسم من الرسالة وتصفية الأطباء حسبه
        $departmentId = null;
        $departmentName = null;
        $depts = $this->tools->searchDepartments($message);
        if (!empty($depts) && $this->messageMentionsDepartmentOrSpecialty($message)) {
            $departmentId = (int) ($depts[0]['department_id'] ?? 0) ?: null;
            $departmentName = (string) ($depts[0]['name'] ?? '');
        }

        $doctors = $this->tools->searchDoctors($message, $departmentId);

        if (empty($doctors)) {
            $draft = 'ما لقيت طبيب مطابق. اكتب اسم الطبيب أو التخصص، مثل قلب، عيون، أطفال، أو باطنية.';
        } elseif (count($doctors) === 1 || $this->tools->isDoctorBioQuestion($message)) {
            $draft = $this->tools->doctorBiography($doctors[0]) . ' إذا بدك أحجز لك معه، احكيلي اليوم والوقت.';
            // Pre-select doctor so the next date/time message goes through booking flow
            if (!($state['booking']['active'] ?? false)) {
                $state['booking'] = array_replace_recursive($this->stateManager->defaultBooking(), $state['booking'] ?? [], [
                    'active' => true,
                    'selected_doctor' => $doctors[0],
                    'selected_department' => [
                        'department_id' => $doctors[0]['department_id'] ?? null,
                        'name' => $doctors[0]['department_name'] ?? null,
                    ],
                    'candidate_doctors' => [],
                ]);
            }
        } else {
            $lines = [];
            foreach (array_slice($doctors, 0, 5) as $i => $doctor) {
                $num = $i + 1;
                $name = (string) ($doctor['full_name'] ?? 'طبيب');
                $spec = (string) ($doctor['specialty'] ?? 'تخصص غير محدد');
                $lines[] = "{$num}. {$name} — {$spec}";
            }
            $label = $departmentName !== '' && $departmentName !== null
                ? "أطباء {$departmentName}"
                : 'الأطباء المتاحون';
            $priceNote = $this->tools->isPriceQuestion($message)
                ? $this->consultationPriceNote($message, $departmentName)
                : '';
            $draft = "{$label}:\n" . implode("\n", $lines)
                . ($priceNote !== '' ? "\n\n{$priceNote}" : '')
                . "\n\nإذا بدك تحجز مع أحدهم، قلي اسمه وبدأنا.";
            // Queue candidates — نجيب كل أطباء القسم حتى ما يضيع أي دكتور عند المطابقة
            if (!($state['booking']['active'] ?? false)) {
                $allCandidates = $departmentId !== null
                    ? $this->tools->searchDoctors('', $departmentId)
                    : $doctors;
                $state['booking'] = array_replace_recursive($this->stateManager->defaultBooking(), $state['booking'] ?? [], [
                    'active' => true,
                    'candidate_doctors' => $allCandidates,
                ]);
            }
        }

        return $this->finish($state, 'ask_doctors', ['doctors' => $doctors, 'quick_replies' => []], $draft, $message);
    }

    private function messageMentionsDepartmentOrSpecialty(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        return $this->containsAny($normalized, [
            'قسم', 'تخصص', 'اخصائي', 'اخصائية',
            'عيون', 'اطفال', 'قلب', 'عظام', 'اعصاب', 'نسائي',
            'هضم', 'تنظير', 'جلد', 'انف', 'اذن', 'حنجره',
            'باطن', 'جراح', 'مسالك',
        ]);
    }

    private function handleLabResultQuestion(string $message, array $state, ?array $patient): array
    {
        $labResult = array_replace_recursive($this->stateManager->defaultLabResult(), $state['lab_result'] ?? []);
        $labResult['active'] = true;
        $state['goal'] = 'lab_results';
        $state['intent'] = 'lab_results';

        if (($labResult['stage'] ?? '') === 'need_otp') {
            $verification = $this->tools->verifyCode($message, $labResult);
            if (($verification['ok'] ?? false) === true) {
                $mail = $this->tools->sendLabResultsEmail($labResult);
                $masked = $this->maskEmail((string) ($labResult['patient_email'] ?? ''));
                $tests = (array) ($labResult['selected_tests'] ?? []);
                $state['lab_result'] = $this->stateManager->defaultLabResult();

                $resultLines = [];
                foreach ($tests as $test) {
                    $testName   = (string) ($test['test_name'] ?? 'فحص');
                    $testDate   = (string) ($test['test_date'] ?? '');
                    $testStatus = (string) ($test['status'] ?? '');
                    $resultText = trim((string) ($test['result_text'] ?? ''));
                    $testDoctor = trim((string) ($test['doctor_name'] ?? ''));
                    $line = "• {$testName}" . ($testDate !== '' ? " ({$testDate})" : '');
                    $line .= "\n  " . ($testStatus === 'Ready'
                        ? 'النتيجة: ' . ($resultText !== '' ? $resultText : 'جاهزة — راجع الإيميل للتفاصيل.')
                        : 'الحالة: قيد الانتظار.');
                    if ($testDoctor !== '') {
                        $line .= "\n  الطبيب: {$testDoctor}";
                    }
                    $resultLines[] = $line;
                }

                $draft = "تم التحقق. نتائج فحوصاتك:\n─────────────────────\n"
                    . implode("\n─────────────────────\n", $resultLines)
                    . "\n─────────────────────\nأُرسلت النتائج أيضاً على {$masked}.";
                return $this->finish($state, 'lab_results_emailed', ['mail' => $mail, 'quick_replies' => []], $draft, $message, false);
            }

            $labResult['otp_attempts'] = (int) ($labResult['otp_attempts'] ?? 0) + 1;
            $reason = (string) ($verification['reason'] ?? 'invalid');
            if ($reason === 'expired') {
                $labResult['stage'] = 'need_national_id';
                $labResult['verification_status'] = 'expired';
            }
            $state['lab_result'] = $labResult;
            $draft = $reason === 'expired'
                ? 'انتهت صلاحية الرمز. اكتب رقم الهوية مرة ثانية حتى أرسل رمز جديد.'
                : 'الرمز مش مطابق. اكتب رمز التحقق المكوّن من 6 أرقام مثل ما وصلك على الإيميل.';
            return $this->finish($state, 'lab_results_otp_invalid', ['reason' => $reason, 'quick_replies' => []], $draft, $message, false);
        }

        $nationalId = $this->tools->extractNationalId($message);
        if ($nationalId !== null) {
            $labResult['patient_national_id'] = $nationalId;
            $labResult['verified_patient'] = null;
        }

        $status = $this->tools->requestedLabStatus($message);
        if ($status !== null) {
            $labResult['requested_status'] = $status;
        }
        if ($this->tools->isLabResultQuestion($message)) {
            $labResult['requested_test_name'] = $message;
        }

        if (empty($labResult['patient_national_id'])) {
            $labResult['stage'] = 'need_national_id';
            $state['lab_result'] = $labResult;
            $draft = 'نتائج الفحوصات معلومات خاصة. اكتب رقم الهوية أولاً حتى أتأكد من ملف المريض، وبعدها أرسل رمز تحقق على الإيميل.';
            return $this->finish($state, 'lab_results_need_national_id', ['quick_replies' => []], $draft, $message, false);
        }

        $verifiedPatient = is_array($labResult['verified_patient'] ?? null)
            ? $labResult['verified_patient']
            : $this->tools->findPatientByNationalId((string) $labResult['patient_national_id']);
        if ($verifiedPatient === null) {
            $labResult['stage'] = 'need_national_id';
            $state['lab_result'] = $labResult;
            $draft = 'ما لقيت ملف مريض مطابق لهذا الرقم. تأكد من رقم الهوية واكتبه مرة ثانية.';
            return $this->finish($state, 'lab_results_national_id_not_found', ['quick_replies' => []], $draft, $message, false);
        }

        $labResult['verified_patient'] = $verifiedPatient;
        $labResult['patient_email'] = (string) ($verifiedPatient['email'] ?? '');
        if ($labResult['patient_email'] === '') {
            $labResult['stage'] = 'need_email';
            $state['lab_result'] = $labResult;
            $draft = 'لقيت ملف المريض، لكن ما في إيميل مسجل عليه. راجع الاستقبال لإضافة الإيميل قبل إرسال النتائج.';
            return $this->finish($state, 'lab_results_missing_email', ['quick_replies' => []], $draft, $message, false);
        }

        $tests = $this->tools->searchPatientLabTests(
            (int) $verifiedPatient['patient_id'],
            (string) ($labResult['requested_test_name'] ?? $message),
            $labResult['requested_status'] ?? null
        );
        if (empty($tests)) {
            $state['lab_result'] = $this->stateManager->defaultLabResult();
            $draft = 'ما لقيت فحوصات مطابقة على هذا الملف. إذا اسم الفحص مختلف، اكتب اسم الفحص كما هو في ورقة المختبر.';
            return $this->finish($state, 'lab_results_empty', ['lab_tests' => [], 'quick_replies' => []], $draft, $message, false);
        }

        $labResult['selected_tests'] = $tests;
        $otp = $this->tools->sendLabResultCode($labResult);
        $labResult['otp_hash'] = $otp['otp_hash'];
        $labResult['otp_expires_at'] = $otp['otp_expires_at'];
        $labResult['otp_attempts'] = 0;
        $labResult['verification_status'] = 'sent';
        $labResult['stage'] = 'need_otp';
        $state['lab_result'] = $labResult;

        $masked = $this->maskEmail((string) $labResult['patient_email']);
        $draft = "لقيت الفحص على ملفك. أرسلت رمز تحقق على {$masked}. اكتب الرمز هون، وبعدها بتشوف النتيجة هون وعلى الإيميل.";

        return $this->finish($state, 'lab_results_code_sent', ['email' => $masked, 'mail' => $otp['send_result'], 'quick_replies' => []], $draft, $message, false);
    }

    private function handleCapabilityQuestion(array $state, string $message): array
    {
        $state['goal'] = 'capabilities';
        $state['intent'] = 'chatbot_capabilities';
        $draft = implode("\n", [
            'بقدر أساعدك في:',
            '1. الرد على أسئلة المستشفى العامة مثل الموقع، الدوام، الأقسام، والخدمات.',
            '2. عرض الأطباء حسب الاسم أو التخصص أو القسم، مع نبذة عن الطبيب.',
            '3. حجز موعد خطوة بخطوة واختيار الطبيب والتاريخ والوقت وسبب الزيارة.',
            '4. تغيير موعد حجز حديث أو مساعدتك في تعديل موعدك إذا كنت مسجل دخول.',
            '5. إلغاء موعدك إذا كنت مسجل دخول وعندك موعد قائم.',
            '6. مراجعة نتائج الفحوصات والتحاليل بعد تسجيل الدخول لحماية خصوصيتك.',
            '7. عرض أسعار أو تكاليف الخدمات المسجلة في النظام إذا كانت متوفرة.',
            '8. التعامل مع الأسئلة الجانبية أثناء الحجز، مثل سؤال عن قسم أو طبيب أو خدمة، ثم نكمل الحجز.',
            '9. تنبيهك للطوارئ إذا ذكرت أعراض خطيرة، بدون تشخيص طبي.',
            'احكيلي طلبك بشكل طبيعي، وأنا بوجهك للخطوة المناسبة.',
        ]);

        return $this->finish($state, 'chatbot_capabilities', ['quick_replies' => []], $draft, $message, false);
    }

    private function handleServiceQuestion(string $message, array $state): array
    {
        $state['goal'] = 'service_info';
        $state['intent'] = 'ask_services';
        $services = $this->tools->searchServices($message);

        if (!empty($services)) {
            $isPriceQuestion = $this->tools->isPriceQuestion($message);
            $lines = array_map(static function (array $service): string {
                $name = (string) ($service['name'] ?? 'الخدمة');
                $department = (string) ($service['department_name'] ?? '');
                $cost = isset($service['base_cost']) ? number_format((float) $service['base_cost'], 2) . ' شيكل' : 'سعر غير متوفر';
                return trim($name . ($department !== '' ? ' - ' . $department : '') . ' - ' . $cost);
            }, array_slice($services, 0, 4));

            if (!$isPriceQuestion) {
                $lines = array_map(static function (array $service): string {
                    $name = (string) ($service['name'] ?? 'الخدمة');
                    $department = (string) ($service['department_name'] ?? '');
                    return trim($name . ($department !== '' ? ' - ' . $department : ''));
                }, array_slice($services, 0, 6));
                $draft = 'من الخدمات المتوفرة في المستشفى: ' . implode(' | ', $lines) . '. إذا بدك سعر خدمة معينة أو بدك أحجز لك موعد، احكيلي اسم الخدمة أو القسم.';
            } else {
                $draft = 'هذه أقرب الخدمات والأسعار الموجودة عندي: ' . implode(' | ', $lines) . '.';
            }
            return $this->finish($state, 'ask_services', ['services' => $services, 'quick_replies' => []], $draft, $message, false);
        }

        if ($this->tools->isPriceQuestion($message)) {
            $doctors = $this->tools->searchDoctors($message);
            if (!empty($doctors)) {
                $doctor = $doctors[0];
                $departmentServices = $this->tools->getServicesByDepartment((int) ($doctor['department_id'] ?? 0));
                if (!empty($departmentServices)) {
                    usort($departmentServices, static fn(array $a, array $b): int => ((float) ($a['base_cost'] ?? 0)) <=> ((float) ($b['base_cost'] ?? 0)));
                    $sample = array_slice($departmentServices, 0, 3);
                    $lines = array_map(static function (array $service): string {
                        return (string) ($service['name'] ?? 'الخدمة') . ' ' . number_format((float) ($service['base_cost'] ?? 0), 2) . ' شيكل';
                    }, $sample);

                    $doctorName = (string) ($doctor['full_name'] ?? 'الطبيب');
                    $departmentName = (string) ($doctor['department_name'] ?? 'القسم');
                    $draft = "ما عندي كشفية منفصلة لكل دكتور داخل البيانات الحالية، لكن الخدمات المسجلة في {$departmentName} المرتبط بـ {$doctorName} هي: " . implode(' | ', $lines) . '.';
                    return $this->finish($state, 'ask_services', ['doctor' => $doctor, 'services' => $departmentServices, 'quick_replies' => []], $draft, $message, false);
                }
            }
        }

        $knowledge = $this->tools->searchHospitalKnowledge($message);
        if ($knowledge !== '') {
            $draft = "حسب المعلومات المتوفرة عندي: {$knowledge}";
            return $this->finish($state, 'hospital_knowledge', ['rag_context' => $knowledge, 'quick_replies' => []], $draft, $message, false);
        }

        return $this->finish($state, 'ask_services', ['services' => [], 'quick_replies' => []], 'ما عندي سعر دقيق لهذا الطلب داخل البيانات الحالية. إذا بدك اكتب اسم الخدمة أو اسم الدكتور أو القسم بشكل أوضح.', $message, false);
    }

    private function handleGeneral(string $message, array $state): array
    {
        $normalized = $this->tools->normalize($message);
        $state['goal'] = 'general';
        $state['intent'] = 'general';

        if ($this->containsAny($normalized, ['مرحبا', 'اهلا', 'هلا', 'السلام', 'صباح الخير', 'مساء الخير'])) {
            return $this->finish($state, 'greeting', ['quick_replies' => []], 'أهلا وسهلا. أنا معك من استقبال مستشفى الأهلي. كيف بقدر أساعدك اليوم؟', $message);
        }

        $ragContext = $this->tools->searchHospitalKnowledge($message);
        if ($ragContext !== '') {
            $draft = "{$ragContext}\n\nإذا بدك مزيد من التفاصيل احكيلي.";
            return $this->finish($state, 'hospital_knowledge', ['rag_context' => $ragContext, 'quick_replies' => []], $draft, $message, false);
        }

        $draft = 'آسف كثير، هاد الموضوع خارج تخصصي شوي! 😅 بس أنا هون وجاهز أساعدك بأي شي طبي — حجز موعد، معلومات عن طبيب أو قسم، نتائج فحوصات، أسعار... في شي من هاد بقدر أساعدك فيه؟';
        return $this->finish($state, 'fallback', ['quick_replies' => []], $draft, $message, false);
    }

    private function handleAppointmentInquiry(array $state, ?array $patient, string $message): array
    {
        // لو المريض مسجل دخول أو أرسل رقم هويته في نفس الرسالة
        if ($patient === null) {
            $nid = $this->tools->extractNationalId($message);
            if ($nid !== null) {
                $patient = $this->tools->findPatientByNationalId($nid);
            }
        }

        // لو لسا ما عندنا بيانات المريض، اطلب رقم الهوية
        if ($patient === null) {
            $state['inquiry_waiting_nid'] = true;
            return $this->finish($state, 'appointment_inquiry_need_nid', ['quick_replies' => []], 'أرسللي رقم هويتك وبعطيك مواعيدك المحجوزة مباشرة.', $message);
        }

        return $this->buildAppointmentInquiryReply($state, $patient, $message);
    }

    private function buildAppointmentInquiryReply(array $state, array $patient, string $message): array
    {
        $appointments = $this->tools->getUpcomingBookedAppointments((int) ($patient['patient_id'] ?? 0));

        if (empty($appointments)) {
            return $this->finish($state, 'appointment_inquiry_empty', ['quick_replies' => []], 'ما لقيت مواعيد قادمة محجوزة باسمك. إذا بدك تحجز موعد جديد، احكيلي.', $message);
        }

        $lines = [];
        foreach ($appointments as $i => $a) {
            $num      = $i + 1;
            $id       = (string) ($a['appointment_id'] ?? '');
            $doctor   = (string) ($a['doctor_name'] ?? 'الطبيب');
            $dept     = (string) ($a['department_name'] ?? '');
            $ts       = strtotime((string) ($a['appointment_datetime'] ?? ''));
            $dateStr  = $ts !== false ? date('Y-m-d', $ts) : '';
            $timeStr  = $ts !== false ? date('H:i', $ts) : '';
            $lines[]  = "{$num}. رقم الحجز: #{$id}\n"
                      . "   الطبيب: {$doctor}" . ($dept ? " — {$dept}" : '') . "\n"
                      . "   التاريخ: {$dateStr}  الوقت: {$timeStr}";
        }

        $name  = (string) ($patient['full_name'] ?? '');
        $count = count($appointments);
        $draft = ($name ? "مواعيد {$name}" : 'مواعيدك المحجوزة')
            . " ({$count}):"
            . "\n─────────────────────\n"
            . implode("\n─────────────────────\n", $lines)
            . "\n─────────────────────\n\nإذا بدك تعدّل أو تلغي موعد، اكتب رقم الحجز.";

        $state['inquiry_waiting_nid'] = false;
        $state['inquiry_shown'] = true;
        $state['inquiry_patient'] = $patient;
        return $this->finish($state, 'appointment_inquiry', ['appointments' => $appointments, 'quick_replies' => []], $draft, $message, false);
    }

    private function handleAppointmentAction(string $message, array $state, ?array $patient): array
    {
        $action = array_replace_recursive($this->stateManager->defaultAppointmentAction(), $state['appointment_action'] ?? []);
        $state['goal'] = 'appointment_action';

        if (!($action['active'] ?? false)) {
            $action['active'] = true;
            $action['type'] = $this->tools->isAppointmentCancelRequest($message) ? 'cancel' : 'reschedule';
            $action['appointment_id_hint'] = $this->tools->extractAppointmentId($message);
        }

        $resolvedPatient = is_array($action['patient'] ?? null) ? $action['patient'] : $patient;
        $patientId = is_array($resolvedPatient) ? (int) ($resolvedPatient['patient_id'] ?? 0) : 0;

        // --- choose_appointment stage ---
        if (($action['stage'] ?? '') === 'choose_appointment') {
            $appointments = is_array($action['candidate_appointments'] ?? null) ? $action['candidate_appointments'] : [];
            $selected = null;

            // حاول تطابق رقم الموعد (مع أو بدون prefix مثل "رقم")
            $hintedId = $this->tools->extractAppointmentId($message);
            // لو ما لقى prefix، حاول الرقم المجرد
            if ($hintedId === null && preg_match('/^\s*#?\s*(\d{1,6})\s*$/', trim($message), $rawM)) {
                $hintedId = (int) $rawM[1];
            }
            if ($hintedId !== null) {
                foreach ($appointments as $appt) {
                    if ((int) ($appt['appointment_id'] ?? 0) === $hintedId) {
                        $selected = $appt;
                        break;
                    }
                }
            }

            if ($selected === null) {
                if (preg_match('/^([1-9])$/', trim($message), $m)) {
                    $idx = (int) $m[1] - 1;
                    if (isset($appointments[$idx])) {
                        $selected = $appointments[$idx];
                    }
                }
            }

            if ($selected === null) {
                $lines = $this->formatAppointmentList($appointments);
                $typeLabel = $action['type'] === 'cancel' ? 'إلغاء' : 'تعديل';
                $state['appointment_action'] = $action;
                return $this->finish($state, 'appointment_action_choose', ['quick_replies' => []], "اكتب رقم الموعد من القائمة:\n{$lines}", $message, false);
            }

            $action['selected_appointment'] = $selected;
            return $this->advanceAfterAppointmentSelection($message, $state, $action, $resolvedPatient);
        }

        // --- confirm_cancel stage ---
        if (($action['stage'] ?? '') === 'confirm_cancel') {
            $normalized = $this->tools->normalize($message);
            if (!$this->containsAny($normalized, ['نعم', 'اه', 'اي', 'اكيد', 'تمام', 'موافق', 'اوك', 'ok', 'yes'])) {
                $action['active'] = false;
                $state['appointment_action'] = $action;
                return $this->finish($state, 'appointment_action_aborted', ['quick_replies' => []], 'تمام، بطلت عملية الإلغاء. موعدك لا يزال مثبتاً. كيف بقدر أساعدك؟', $message, false);
            }

            $selected = is_array($action['selected_appointment'] ?? null) ? $action['selected_appointment'] : [];
            $appointmentId = (int) ($selected['appointment_id'] ?? 0);
            $cancelPatientId = is_array($resolvedPatient) ? (int) ($resolvedPatient['patient_id'] ?? 0) : 0;
            if ($cancelPatientId === 0) {
                $cancelPatientId = (int) ($selected['patient_id'] ?? 0);
            }

            try {
                $this->tools->cancelAppointment($appointmentId, $cancelPatientId);
                $action['active'] = false;
                $state['appointment_action'] = $action;
                $doctorName = (string) ($selected['doctor_name'] ?? 'الطبيب');
                return $this->finish($state, 'appointment_cancelled', ['quick_replies' => []], "تم إلغاء موعدك مع {$doctorName}. إذا أردت حجز موعد جديد أو أي مساعدة أخرى، أنا هون.", $message, false);
            } catch (Throwable) {
                return $this->finish($state, 'appointment_cancel_failed', ['quick_replies' => []], 'ما قدرت أكمل الإلغاء. تواصل مع الاستقبال على الرقم 1700200400.', $message, false);
            }
        }

        // --- need_new_date stage ---
        if (($action['stage'] ?? '') === 'need_new_date') {
            $date = $this->tools->extractDate($message);
            $time = $this->tools->extractTime($message);
            // المريض بعت وقت بدل تاريخ ← نفهم إنه بده نفس التاريخ بوقت جديد
            if ($date === null && $time !== null) {
                $apptDatetime = (string) ($action['selected_appointment']['appointment_datetime'] ?? '');
                if (strlen($apptDatetime) >= 10) {
                    $date = substr($apptDatetime, 0, 10);
                }
            }
            if ($date !== null) {
                $action['requested_date'] = $date;
                $action['stage'] = 'need_new_time';
                $state['appointment_action'] = $action;
                return $this->finish($state, 'appointment_action_need_time', ['quick_replies' => []], "تمام، بتاريخ {$date}. أي ساعة بناسبك؟ مثلاً 10:00 أو 10:30.", $message, false);
            }
            $state['appointment_action'] = $action;
            return $this->finish($state, 'appointment_action_need_date', ['quick_replies' => []], 'اكتب التاريخ الجديد للموعد، مثلاً بكرا أو 2026-05-20.', $message, false);
        }

        // --- need_new_time stage ---
        if (($action['stage'] ?? '') === 'need_new_time') {
            $date = $this->tools->extractDate($message);
            if ($date !== null) {
                $action['requested_date'] = $date;
            }
            $time = $this->tools->extractTime($message);
            if ($time !== null) {
                $selected = is_array($action['selected_appointment'] ?? null) ? $action['selected_appointment'] : [];
                $appointmentId = (int) ($selected['appointment_id'] ?? 0);
                $reschedPatientId = is_array($resolvedPatient) ? (int) ($resolvedPatient['patient_id'] ?? 0) : 0;
                // Fallback: use patient_id stored in the appointment (was validated when originally loaded)
                if ($reschedPatientId === 0) {
                    $reschedPatientId = (int) ($selected['patient_id'] ?? 0);
                }
                $requestedDate = (string) ($action['requested_date'] ?? '');

                try {
                    $updated = $this->tools->rescheduleAppointment($appointmentId, $reschedPatientId, $requestedDate, $time);
                    $action['active'] = false;
                    $state['appointment_action'] = $action;
                    $doctorName = (string) ($updated['doctor_name'] ?? $selected['doctor_name'] ?? 'الطبيب');
                    $datetime = (string) ($updated['appointment_datetime'] ?? $requestedDate . ' ' . $time . ':00');
                    return $this->finish($state, 'appointment_rescheduled', ['appointment' => $updated, 'quick_replies' => []], "تم تعديل موعدك مع {$doctorName} إلى {$datetime}.", $message, false);
                } catch (Throwable $reschedEx) {
                    $state['appointment_action'] = $action;
                    $reschedErrMsg = $reschedEx->getMessage();
                    if (mb_strpos($reschedErrMsg, 'محجوز') !== false) {
                        $reschedDraft = "هذا الوقت محجوز مع مريض آخر عند نفس الطبيب. اختار وقت ثاني — الأوقات المتاحة كل نصف ساعة بين 9:00 و15:00.";
                    } elseif (mb_strpos($reschedErrMsg, 'ساعات العمل') !== false || mb_strpos($reschedErrMsg, 'نصف الساعة') !== false || mb_strpos($reschedErrMsg, 'بين') !== false) {
                        $reschedDraft = "الوقت خارج ساعات الدوام أو غير على النصف ساعة. الأوقات المتاحة 9:00 و9:30 و10:00 وهكذا حتى 15:00.";
                    } elseif (mb_strpos($reschedErrMsg, 'المستقبل') !== false) {
                        $reschedDraft = "التاريخ أو الوقت اللي كتبته في الماضي. اكتب موعد قادم.";
                    } else {
                        $reschedDraft = 'ما قدرت أعدل الموعد على هذا الوقت. اكتب وقت أو تاريخ ثاني.';
                    }
                    return $this->finish($state, 'appointment_reschedule_failed', ['quick_replies' => []], $reschedDraft, $message, false);
                }
            }
            $dateStr = (string) ($action['requested_date'] ?? '');
            $state['appointment_action'] = $action;
            return $this->finish($state, 'appointment_action_need_time', ['quick_replies' => []], "اكتب الوقت الجديد للموعد بتاريخ {$dateStr}، مثلاً 10:00 أو 10:30.", $message, false);
        }

        // --- no patient yet: ask for national ID or use logged-in patient ---
        if ($patientId <= 0) {
            $nationalId = $this->tools->extractNationalId($message);
            if ($nationalId !== null) {
                $foundPatient = $this->tools->findPatientByNationalId($nationalId);
                if ($foundPatient === null) {
                    $action['stage'] = 'need_national_id';
                    $state['appointment_action'] = $action;
                    return $this->finish($state, 'appointment_action_patient_not_found', ['quick_replies' => []], 'ما لقيت ملف مريض لرقم الهوية هذا. تأكد من الرقم وحاول مرة ثانية.', $message, false);
                }
                $action['patient'] = $foundPatient;
                $patientId = (int) $foundPatient['patient_id'];
                $resolvedPatient = $foundPatient;
            } else {
                $action['stage'] = 'need_national_id';
                $state['appointment_action'] = $action;
                $typeLabel = $action['type'] === 'cancel' ? 'إلغاء' : 'تعديل';
                return $this->finish($state, 'appointment_action_need_id', ['quick_replies' => []], "حتى أقدر أساعدك في {$typeLabel} الموعد، اكتب رقم الهوية أولاً.", $message, false);
            }
        }

        // --- load upcoming appointments ---
        $appointments = $this->tools->getUpcomingBookedAppointments($patientId);
        if (empty($appointments)) {
            $action['active'] = false;
            $state['appointment_action'] = $action;
            return $this->finish($state, 'appointment_action_no_appointments', ['quick_replies' => []], 'ما لقيت مواعيد قادمة محجوزة. إذا بدك تحجز موعد جديد، احكيلي.', $message, false);
        }

        $action['candidate_appointments'] = $appointments;

        $hintId = (int) ($action['appointment_id_hint'] ?? 0);
        if ($hintId > 0) {
            foreach ($appointments as $appt) {
                if ((int) ($appt['appointment_id'] ?? 0) === $hintId) {
                    $action['selected_appointment'] = $appt;
                    break;
                }
            }
        }

        if (!empty($action['selected_appointment'])) {
            return $this->advanceAfterAppointmentSelection($message, $state, $action, $resolvedPatient);
        }

        if (count($appointments) === 1) {
            $action['selected_appointment'] = $appointments[0];
            return $this->advanceAfterAppointmentSelection($message, $state, $action, $resolvedPatient);
        }

        $action['stage'] = 'choose_appointment';
        $state['appointment_action'] = $action;
        $typeLabel = $action['type'] === 'cancel' ? 'إلغاء' : 'تعديل';
        $lines = $this->formatAppointmentList($appointments);
        return $this->finish($state, 'appointment_action_choose', ['appointments' => $appointments, 'quick_replies' => []], "لقيت " . count($appointments) . " مواعيد محجوزة. أي موعد بدك {$typeLabel}؟\n{$lines}", $message, false);
    }

    private function advanceAfterAppointmentSelection(string $message, array $state, array $action, ?array $resolvedPatient): array
    {
        $appt = is_array($action['selected_appointment'] ?? null) ? $action['selected_appointment'] : [];
        $doctorName = (string) ($appt['doctor_name'] ?? 'الطبيب');
        $datetime = (string) ($appt['appointment_datetime'] ?? '');

        if ($action['type'] === 'cancel') {
            $action['stage'] = 'confirm_cancel';
            $state['appointment_action'] = $action;
            return $this->finish($state, 'appointment_action_confirm_cancel', ['appointment' => $appt, 'quick_replies' => []], "تأكيد: بدك تلغي موعدك مع {$doctorName} بتاريخ {$datetime}؟ اكتب 'نعم' للتأكيد أو 'لا' للإلغاء.", $message, false);
        }

        $date = $this->tools->extractDate($message);
        $time = $this->tools->extractTime($message);

        if ($date !== null) {
            $action['requested_date'] = $date;
        }
        if ($time !== null) {
            $action['requested_time'] = $time;
        }

        if ($date !== null && $time !== null) {
            $appointmentId = (int) ($appt['appointment_id'] ?? 0);
            $reschedPatientId = is_array($resolvedPatient) ? (int) ($resolvedPatient['patient_id'] ?? 0) : 0;
            if ($reschedPatientId === 0) {
                $reschedPatientId = (int) ($appt['patient_id'] ?? 0);
            }
            try {
                $updated = $this->tools->rescheduleAppointment($appointmentId, $reschedPatientId, $date, $time);
                $action['active'] = false;
                $state['appointment_action'] = $action;
                $newDoctor = (string) ($updated['doctor_name'] ?? $doctorName);
                $newDatetime = (string) ($updated['appointment_datetime'] ?? $date . ' ' . $time . ':00');
                return $this->finish($state, 'appointment_rescheduled', ['appointment' => $updated, 'quick_replies' => []], "تم تعديل موعدك مع {$newDoctor} إلى {$newDatetime}.", $message, false);
            } catch (Throwable $advEx) {
                $action['stage'] = 'need_new_time';
                $state['appointment_action'] = $action;
                $advErrMsg = $advEx->getMessage();
                if (mb_strpos($advErrMsg, 'محجوز') !== false) {
                    $advDraft = "هذا الوقت محجوز مع مريض آخر عند نفس الطبيب. اختار وقت ثاني — الأوقات المتاحة كل نصف ساعة بين 9:00 و15:00.";
                } elseif (mb_strpos($advErrMsg, 'ساعات العمل') !== false || mb_strpos($advErrMsg, 'نصف الساعة') !== false || mb_strpos($advErrMsg, 'بين') !== false) {
                    $advDraft = "الوقت خارج ساعات الدوام أو غير على النصف ساعة. الأوقات المتاحة 9:00 و9:30 و10:00 وهكذا حتى 15:00.";
                } elseif (mb_strpos($advErrMsg, 'المستقبل') !== false) {
                    $advDraft = "التاريخ أو الوقت اللي كتبته في الماضي. اكتب موعد قادم.";
                } else {
                    $advDraft = 'ما قدرت أعدل الموعد على هذا الوقت. اكتب وقت أو تاريخ ثاني.';
                }
                return $this->finish($state, 'appointment_reschedule_failed', ['quick_replies' => []], $advDraft, $message, false);
            }
        }

        $action['stage'] = 'need_new_date';
        $state['appointment_action'] = $action;
        return $this->finish($state, 'appointment_action_need_date', ['appointment' => $appt, 'quick_replies' => []], "موعدك الحالي مع {$doctorName} بتاريخ {$datetime}. لأي تاريخ بدك أغير؟ اكتب مثلاً بكرا أو 2026-05-20.", $message, false);
    }

    private function formatAppointmentList(array $appointments): string
    {
        $lines = [];
        foreach ($appointments as $i => $appt) {
            $num        = $i + 1;
            $id         = (int) ($appt['appointment_id'] ?? 0);
            $doctorName = (string) ($appt['doctor_name'] ?? 'طبيب');
            $dept       = (string) ($appt['department_name'] ?? '');
            $ts         = strtotime((string) ($appt['appointment_datetime'] ?? ''));
            $dateStr    = $ts !== false ? date('Y-m-d', $ts) : '';
            $timeStr    = $ts !== false ? date('H:i', $ts) : '';
            $lines[]    = "{$num}. رقم الحجز: #{$id}\n"
                        . "   الطبيب: {$doctorName}" . ($dept ? " — {$dept}" : '') . "\n"
                        . "   التاريخ: {$dateStr}  الوقت: {$timeStr}";
        }
        return implode("\n─────────────────────\n", $lines);
    }

    private function shouldHandleRecentBookedAppointmentChange(string $message, array $state): bool
    {
        $booking = $state['booking'] ?? [];
        $appointment = $booking['appointment'] ?? null;
        if (($booking['stage'] ?? '') !== 'booked' || !is_array($appointment) || empty($appointment['appointment_id'])) {
            return false;
        }

        $bookedAt = (int) ($booking['booked_at'] ?? 0);
        if ($bookedAt > 0 && time() - $bookedAt > 3600) {
            return false;
        }

        // Only handle if the user actually mentions a new date or time (prevents false trigger from "تعديل الموعد")
        if (($this->tools->isChangeTimeRequest($message) || $this->tools->isChangeDateRequest($message))
            && ($this->tools->extractTime($message) !== null || $this->tools->extractDate($message) !== null)) {
            return true;
        }

        // إلغاء الموعد بعد التثبيت مباشرة
        $normalized = $this->tools->normalize($message);
        if ($this->containsAny($normalized, ['الغي', 'الغ', 'الغاء', 'بطل', 'بطلت', 'احذف'])
            && $this->containsAny($normalized, ['موعد', 'الموعد', 'حجز', 'الحجز'])) {
            return true;
        }

        return $this->containsAny($normalized, ['غير الموعد', 'غيرلي الموعد', 'بدل الموعد', 'عدل الموعد', 'بدي اغير الموعد'])
            && ($this->tools->extractTime($message) !== null || $this->tools->extractDate($message) !== null);
    }

    private function handleRecentBookedAppointmentChange(string $message, array $state, ?array $patient): array
    {
        $booking = $state['booking'] ?? [];
        $appointment = is_array($booking['appointment'] ?? null) ? $booking['appointment'] : [];
        $appointmentId = (int) ($appointment['appointment_id'] ?? 0);
        $patientId = (int) (($patient['patient_id'] ?? null) ?? ($appointment['patient_id'] ?? 0));

        // فحص طلب الإلغاء أولاً
        $normMsg = $this->tools->normalize($message);
        if ($this->containsAny($normMsg, ['الغي', 'الغ', 'الغاء', 'بطل', 'بطلت', 'احذف'])
            && $this->containsAny($normMsg, ['موعد', 'الموعد', 'حجز', 'الحجز'])) {
            if ($appointmentId <= 0 || $patientId <= 0) {
                return $this->finish($state, 'appointment_login_required', ['quick_replies' => []], 'حتى ألغي الموعد، لازم يكون عندي رقم الموعد وبيانات المريض.', $message);
            }
            try {
                $this->tools->cancelAppointment($appointmentId, $patientId);
                $booking['stage'] = 'idle';
                $booking['active'] = false;
                $state['booking'] = $booking;
                $doctorName = (string) ($appointment['doctor_name'] ?? 'الطبيب');
                return $this->finish($state, 'appointment_cancelled', ['quick_replies' => []], "تم إلغاء موعدك مع {$doctorName}. إذا بدك تحجز موعد جديد أو أي مساعدة، أنا هون.", $message, false);
            } catch (Throwable $e) {
                return $this->finish($state, 'appointment_cancel_failed', ['quick_replies' => []], 'ما قدرت أكمل الإلغاء: ' . $e->getMessage() . '. تواصل مع الاستقبال على الرقم 1700200400.', $message, false);
            }
        }

        if ($appointmentId <= 0 || $patientId <= 0) {
            return $this->finish($state, 'appointment_login_required', ['quick_replies' => []], 'حتى أعدل الموعد، لازم يكون عندي رقم الموعد وبيانات المريض بشكل صحيح. حاول تسجيل الدخول أو احجز من جديد.', $message);
        }

        $currentTs = strtotime((string) ($appointment['appointment_datetime'] ?? ''));
        $currentDate = $currentTs !== false ? date('Y-m-d', $currentTs) : null;
        $currentTime = $currentTs !== false ? date('H:i', $currentTs) : null;

        $requestedDate = $this->tools->extractDate($message);
        $requestedTime = $this->tools->extractTime($message);

        // If neither date nor time was given, ask for the new date rather than falling back to current values
        if ($requestedDate === null && $requestedTime === null) {
            $doctorName = (string) ($appointment['doctor_name'] ?? 'الطبيب');
            $datetime   = (string) ($appointment['appointment_datetime'] ?? '');
            return $this->finish($state, 'appointment_reschedule_need_date', ['appointment' => $appointment, 'quick_replies' => []], "موعدك الحالي مع {$doctorName} بتاريخ {$datetime}. لأي تاريخ بدك أغيّره؟ اكتب مثلاً بكرا أو 2026-06-20.", $message);
        }

        // Fall back to current date/time only when the user provides the other half
        $requestedDate = $requestedDate ?? $currentDate;
        $requestedTime = $requestedTime ?? $currentTime;

        if ($requestedDate === null) {
            return $this->finish($state, 'appointment_reschedule_need_date', ['appointment' => $appointment, 'quick_replies' => []], 'أكيد. لأي تاريخ بدك أغير الموعد؟ اكتب مثلاً بكرا أو 2026-05-20.', $message);
        }

        if ($requestedTime === null) {
            return $this->finish($state, 'appointment_reschedule_need_time', ['appointment' => $appointment, 'quick_replies' => []], 'أكيد. لأي ساعة بدك أغير الموعد؟ اكتب مثلاً 10:00 أو 11:30.', $message);
        }

        try {
            $updated = $this->tools->rescheduleAppointment($appointmentId, $patientId, $requestedDate, $requestedTime);
            $booking['appointment'] = $updated;
            $booking['selected_date'] = $requestedDate;
            $booking['selected_time'] = $requestedTime;
            $booking['stage'] = 'booked';
            $booking['active'] = false;
            $state['booking'] = $booking;
            $state['goal'] = 'appointment_reschedule';

            $doctorName = (string) ($updated['doctor_name'] ?? $appointment['doctor_name'] ?? $booking['selected_doctor']['full_name'] ?? 'الطبيب');
            $datetime = (string) ($updated['appointment_datetime'] ?? ($requestedDate . ' ' . $requestedTime . ':00'));
            $draft = "تم تعديل موعدك مع {$doctorName} إلى {$datetime}.";
            return $this->finish($state, 'appointment_rescheduled', ['appointment' => $updated, 'quick_replies' => []], $draft, $message);
        } catch (Throwable $e) {
            $errMsg = $e->getMessage();
            if (mb_strpos($errMsg, 'محجوز') !== false) {
                $draft = "هذا الوقت محجوز مسبقاً مع مريض آخر عند نفس الطبيب. اختار وقت ثاني — الأوقات المتاحة كل نصف ساعة بين 9:00 و 15:00.";
            } elseif (mb_strpos($errMsg, 'ساعات العمل') !== false || mb_strpos($errMsg, 'نصف الساعة') !== false || mb_strpos($errMsg, 'بين') !== false) {
                $draft = "الوقت المطلوب خارج ساعات الدوام أو غير على النصف ساعة. الدوام 9:00 – 15:00، والأوقات مثل 9:00 و9:30 و10:00. اكتب وقت مناسب.";
            } elseif (mb_strpos($errMsg, 'المستقبل') !== false) {
                $draft = "التاريخ أو الوقت اللي كتبته في الماضي. اكتب موعد قادم.";
            } else {
                $draft = 'ما قدرت أعدل الموعد على هذا الوقت. اكتب وقت أو تاريخ ثاني.';
            }
            return $this->finish($state, 'appointment_reschedule_failed', ['appointment' => $appointment, 'error' => $errMsg, 'quick_replies' => []], $draft, $message);
        }
    }

    private function answerSideQuestion(string $message, ?array $patient): ?string
    {
        if ($this->tools->isLabResultQuestion($message)) {
            return 'نتائج الفحوصات معلومات خاصة. بعد ما نخلص خطوة الحجز الحالية، اكتب رقم الهوية وسأرسل رمز تحقق على الإيميل قبل إرسال النتيجة.';
        }

        if ($this->tools->isServiceQuestion($message)) {
            $services = $this->tools->searchServices($message);
            if (empty($services)) {
                return 'ما لقيت خدمة مطابقة بدقة في النظام الحالي.';
            }

            $items = array_map(static function (array $service): string {
                $name = (string) ($service['name'] ?? 'الخدمة');
                $cost = isset($service['base_cost']) ? number_format((float) $service['base_cost'], 2) . ' شيكل' : 'سعر غير متوفر';
                return "{$name}: {$cost}";
            }, array_slice($services, 0, 3));

            return 'بالنسبة للخدمات أو الأسعار: ' . implode(' | ', $items) . '.';
        }

        if ($this->tools->isDoctorBioQuestion($message)) {
            $doctors = $this->tools->searchDoctors($message);
            if (!empty($doctors)) {
                return $this->tools->doctorBiography($doctors[0]);
            }
        }

        if ($this->tools->isDepartmentQuestion($message)) {
            $departments = $this->tools->searchDepartments($message);
            if (empty($departments)) {
                return 'ما عندي معلومة دقيقة عن هذا القسم من سؤالك الحالي.';
            }
            $department = $departments[0];
            return trim((string) ($department['name'] ?? 'القسم') . ' موجود في ' . (string) ($department['location'] ?? '') . '، والدوام: ' . (string) ($department['working_hours'] ?? 'غير محدد'));
        }

        if ($this->tools->isDoctorQuestion($message)) {
            $doctors = $this->tools->searchDoctors($message);
            if (empty($doctors)) {
                return 'ما لقيت طبيب مطابق لسؤالك.';
            }
            $sideLines = [];
            foreach (array_slice($doctors, 0, 3) as $i => $doc) {
                $num = $i + 1;
                $name = (string) ($doc['full_name'] ?? 'طبيب');
                $spec = (string) ($doc['specialty'] ?? '');
                $sideLines[] = "{$num}. {$name}" . ($spec !== '' ? " — {$spec}" : '');
            }
            return "الأطباء الأقرب لسؤالك:\n" . implode("\n", $sideLines);
        }

        return null;
    }

    private function currentBookingQuestion(array $booking): string
    {
        if (empty($booking['selected_doctor'])) {
            return 'نكمل الحجز: لأي طبيب أو تخصص بدك الموعد؟';
        }
        if (empty($booking['selected_date'])) {
            return 'نكمل الحجز: أي يوم بناسبك؟';
        }
        if (empty($booking['selected_time'])) {
            return 'نكمل الحجز: أي وقت بناسبك؟';
        }
        if (empty($booking['reason'])) {
            return 'نكمل الحجز: شو سبب الزيارة باختصار؟';
        }
        if (empty($booking['patient_phone'])) {
            return 'نكمل الحجز: اكتب رقم هاتف المريض.';
        }
        if (empty($booking['patient_email'])) {
            return 'نكمل الحجز: اكتب إيميلك حتى أرسل رمز التأكيد.';
        }
        if (($booking['verification_status'] ?? '') === 'sent') {
            return 'نكمل الحجز: اكتب رمز التأكيد من الإيميل.';
        }

        return 'نكمل الحجز؟';
    }

    private function continuationReminder(array $booking): string
    {
        // لو أجبنا على سؤال جانبي، نسأل باختصار بدل تكرار نفس السؤال
        if (!empty($booking['last_missing_field'])) {
            return 'بدك نكمل الحجز؟ اكتب إجابتك وأنا جاهز.';
        }
        return $this->currentBookingQuestion($booking);
    }

    private function isBookingSideQuestion(string $message, array $booking): bool
    {
        if (($booking['stage'] ?? '') === 'need_doctor' || empty($booking['selected_doctor'])) {
            return false;
        }
        if (($booking['stage'] ?? '') === 'need_reason') {
            return false;
        }
        if ($this->tools->extractDate($message) !== null || $this->tools->extractTime($message) !== null || $this->tools->extractEmail($message) !== null || $this->tools->extractNationalId($message) !== null) {
            return false;
        }

        if ($this->tools->isLabResultQuestion($message) || $this->tools->isServiceQuestion($message) || $this->tools->isDoctorBioQuestion($message)) {
            return true;
        }

        $normalized = $this->tools->normalize($message);
        return $this->containsAny($normalized, ['شو', 'وين', 'مين', 'دوام', 'قسم', 'خدمه', 'خدمة', 'سعر']);
    }

    private function finish(array $state, string $intent, array $data, string $draft, string $userMessage, bool $allowLlm = true): array
    {
        $state['intent'] = $intent;
        $reply = $this->replyBuilder->finalReply($userMessage, $draft, [
            'state' => [
                'goal' => $state['goal'] ?? 'general',
                'intent' => $intent,
                'memory_summary' => $state['memory_summary'] ?? '',
                'booking' => $this->publicBooking($state['booking'] ?? []),
                'lab_result' => $this->publicLabResult($state['lab_result'] ?? []),
            ],
            'turns' => $state['turns'] ?? [],
            'tool_result' => $data,
        ], $allowLlm);

        $state = $this->stateManager->appendTurn($state, 'bot', $reply);
        $this->stateManager->save($state);

        $data['conversation_state'] = [
            'goal' => $state['goal'] ?? 'general',
            'booking' => $this->publicBooking($state['booking'] ?? []),
            'lab_result' => $this->publicLabResult($state['lab_result'] ?? []),
        ];
        if (empty($data['quick_replies'])) {
            $data['quick_replies'] = [];
        }

        return [
            'intent' => $intent,
            'data' => $data,
            'reply' => $reply,
        ];
    }

    private function plainResponse(string $intent, array $data, string $reply): array
    {
        $data['quick_replies'] = [];
        return ['intent' => $intent, 'data' => $data, 'reply' => $reply];
    }

    private function consultationPriceNote(string $message, ?string $departmentName): string
    {
        $prices = [
            'قلب'     => '180 شيكل',
            'شرايين'  => '180 شيكل',
            'اطفال'   => '130 شيكل',
            'هضمي'    => '165 شيكل',
            'تنظير'   => '165 شيكل',
            'جراحه'   => '185 شيكل',
            'جراحة'   => '185 شيكل',
            'باطني'   => '150 شيكل',
            'نسائي'   => '165 شيكل',
            'توليد'   => '165 شيكل',
            'طوارئ'   => '155 شيكل',
            'عيون'    => '140 شيكل',
            'عظام'    => '170 شيكل',
            'مفاصل'   => '170 شيكل',
            'اذن'     => '145 شيكل',
            'انف'     => '145 شيكل',
            'حنجره'   => '145 شيكل',
            'جلديه'   => '155 شيكل',
            'جلد'     => '155 شيكل',
            'اعصاب'   => '175 شيكل',
            'اشعه'    => '80 شيكل (أشعة سينية)',
        ];

        $normalized = $this->tools->normalize($message . ' ' . ($departmentName ?? ''));
        foreach ($prices as $keyword => $price) {
            if (str_contains($normalized, $keyword)) {
                return "سعر الاستشارة: {$price}";
            }
        }
        return '';
    }

    private function publicBooking(array $booking): array
    {
        return [
            'active' => (bool) ($booking['active'] ?? false),
            'stage' => $booking['stage'] ?? 'idle',
            'selected_department' => $booking['selected_department'] ?? null,
            'selected_doctor' => $booking['selected_doctor'] ?? null,
            'selected_date' => $booking['selected_date'] ?? null,
            'selected_time' => $booking['selected_time'] ?? null,
            'reason' => $booking['reason'] ?? null,
            'patient_email' => !empty($booking['patient_email']) ? $this->maskEmail((string) $booking['patient_email']) : null,
            'patient_national_id' => !empty($booking['patient_national_id']) ? $this->maskNationalId((string) $booking['patient_national_id']) : null,
            'patient_phone' => !empty($booking['patient_phone']) ? $this->maskPhone((string) $booking['patient_phone']) : null,
            'patient_name' => $booking['patient_name'] ?? null,
            'new_patient_requested' => (bool) ($booking['new_patient_requested'] ?? false),
            'verified_patient' => !empty($booking['verified_patient']['patient_id']),
            'verification_status' => $booking['verification_status'] ?? 'not_started',
            'last_missing_field' => $booking['last_missing_field'] ?? null,
            'candidate_doctors_count' => count($booking['candidate_doctors'] ?? []),
        ];
    }

    private function publicLabResult(array $labResult): array
    {
        return [
            'active' => (bool) ($labResult['active'] ?? false),
            'stage' => $labResult['stage'] ?? 'idle',
            'patient_national_id' => !empty($labResult['patient_national_id']) ? $this->maskNationalId((string) $labResult['patient_national_id']) : null,
            'patient_email' => !empty($labResult['patient_email']) ? $this->maskEmail((string) $labResult['patient_email']) : null,
            'verification_status' => $labResult['verification_status'] ?? 'not_started',
            'tests_count' => count($labResult['selected_tests'] ?? []),
        ];
    }

    private function formatDoctorNames(array $doctors): string
    {
        $names = array_map(static fn(array $doctor) => (string) ($doctor['full_name'] ?? 'طبيب'), $doctors);
        return implode('، ', array_filter($names));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = mb_substr($local, 0, min(2, mb_strlen($local, 'UTF-8')), 'UTF-8');
        return $prefix . str_repeat('*', max(2, mb_strlen($local, 'UTF-8') - mb_strlen($prefix, 'UTF-8'))) . '@' . $domain;
    }

    private function maskNationalId(string $nationalId): string
    {
        $digits = preg_replace('/\D+/', '', $nationalId) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 2) . str_repeat('*', max(2, strlen($digits) - 4)) . substr($digits, -2);
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3) . str_repeat('*', max(2, strlen($digits) - 5)) . substr($digits, -2);
    }

    private function isFirstVisitStatement(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        return $this->containsAny($normalized, [
            'اول مره',
            'اول مرة',
            'اول زياره',
            'اول زيارة',
            'اول مره بزور',
            'اول مرة بزور',
            'مريض جديد',
            'جديد عندكم',
            'ما عندي ملف',
            'مش مسجل',
        ]);
    }

    private function extractPatientName(string $message): ?string
    {
        if ($this->isFirstVisitStatement($message)
            || $this->tools->extractEmail($message) !== null
            || $this->tools->extractNationalId($message) !== null
            || $this->tools->extractVerificationCode($message) !== null
            || $this->tools->extractPhone($message) !== null
            || $this->tools->extractDate($message) !== null
            || $this->tools->extractTime($message) !== null) {
            return null;
        }

        $name = trim($message);
        $name = preg_replace('/^(انا\s+اسمي|أنا\s+اسمي|اسمي|الاسم|اسم المريض)\s+/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $normalized = $this->tools->normalize($name);

        if (mb_strlen($normalized, 'UTF-8') < 3 || mb_strlen($normalized, 'UTF-8') > 80) {
            return null;
        }

        if ($this->containsAny($normalized, ['حجز', 'موعد', 'بكرا', 'اليوم', 'فحص', 'تخطي', 'بدون'])) {
            return null;
        }

        return $name;
    }

    private function isResetRequest(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        return in_array($normalized, ['reset', 'restart'], true)
            || $this->containsAny($normalized, ['ابدا من جديد', 'ابدأ من جديد', 'صفر المحادثه', 'صفر المحادثة']);
    }

    private function isGreeting(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        if (!$this->containsAny($normalized, [
            'مرحبا', 'اهلا', 'أهلا', 'هلا', 'السلام عليكم', 'هاي', 'صباح الخير', 'مساء الخير',
        ])) {
            return false;
        }
        // لو الرسالة فيها نية واضحة، ما نعاملها كترحيب بحت
        $hasIntent = $this->containsAny($normalized, [
            'بدي', 'احجز', 'حجز', 'موعد', 'نتيجه', 'نتيجة', 'تحليل', 'فحص',
            'طبيب', 'دكتور', 'الغ', 'اعدل', 'تعديل', 'وجع', 'ألم', 'مريض',
            'عندي', 'عيادة', 'قسم', 'سعر', 'تامين', 'اعرف', 'بعرف',
        ]);
        return !$hasIntent && mb_strlen(trim($message)) < 40;
    }

    private function isSmalltalk(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        if (!$this->containsAny($normalized, [
            'كيفك', 'كيف الحال', 'شو اخبارك', 'شو أخبارك', 'طمني عنك', 'شلونك',
        ])) {
            return false;
        }
        $hasIntent = $this->containsAny($normalized, [
            'بدي', 'احجز', 'حجز', 'موعد', 'نتيجه', 'نتيجة', 'تحليل', 'فحص',
            'طبيب', 'دكتور', 'الغ', 'اعدل', 'وجع', 'ألم', 'عندي', 'قسم',
        ]);
        return !$hasIntent && mb_strlen(trim($message)) < 40;
    }

    private function isDepartmentLocationQuestion(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        return $this->tools->isDepartmentQuestion($message)
            && $this->containsAny($normalized, ['وين', 'موقع', 'طابق', 'دوام']);
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $this->tools->normalize((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
