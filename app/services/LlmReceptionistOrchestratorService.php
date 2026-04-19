<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/SessionAuth.php';
require_once __DIR__ . '/ReceptionistStateManager.php';
require_once __DIR__ . '/ReceptionistToolService.php';
require_once __DIR__ . '/ReceptionistSafetyGuard.php';
require_once __DIR__ . '/LlmReceptionistResponseBuilder.php';

class LlmReceptionistOrchestratorService
{
    private ReceptionistStateManager $stateManager;
    private ReceptionistToolService $tools;
    private ReceptionistSafetyGuard $safety;
    private LlmReceptionistResponseBuilder $replyBuilder;

    public function __construct()
    {
        $this->stateManager = new ReceptionistStateManager();
        $this->tools = new ReceptionistToolService();
        $this->safety = new ReceptionistSafetyGuard();
        $this->replyBuilder = new LlmReceptionistResponseBuilder();
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

        $booking = $state['booking'] ?? [];
        if (($booking['active'] ?? false) === true || $this->tools->isBookingRequest($message)) {
            return $this->handleBooking($message, $state, $patient, $safety);
        }

        if (($safety['level'] ?? 'normal') === 'medical_general') {
            $draft = 'بقدر أساعدك بتوجيه عام فقط، بدون تشخيص. احكيلي الأعراض بشكل أوضح: من متى بدأت؟ وهل في حرارة، ألم قوي، أو مرض سابق؟';
            $state['goal'] = 'medical_guidance';
            $state['intent'] = 'medical_general';
            return $this->finish($state, 'medical_general', ['safety' => $safety], $draft, $message);
        }

        if ($this->tools->isDepartmentQuestion($message)) {
            return $this->handleDepartmentQuestion($message, $state);
        }

        if ($this->tools->isDoctorQuestion($message)) {
            return $this->handleDoctorQuestion($message, $state);
        }

        return $this->handleGeneral($message, $state);
    }

    private function handleBooking(string $message, array $state, ?array $patient, array $safety): array
    {
        $booking = array_replace_recursive($this->stateManager->defaultBooking(), $state['booking'] ?? []);
        $booking['active'] = true;
        $state['goal'] = 'booking';
        $state['intent'] = 'book_appointment';

        if (($booking['stage'] ?? '') === 'need_otp') {
            $verification = $this->tools->verifyCode($message, $booking);
            if (($verification['ok'] ?? false) === true) {
                $booking['verification_status'] = 'verified';
                try {
                    $appointment = $this->tools->createAppointment($booking);
                    $booking['appointment'] = $appointment;
                    $booking['active'] = false;
                    $booking['stage'] = 'booked';
                    $state['booking'] = $booking;
                    $doctor = (string) ($appointment['doctor_name'] ?? $booking['selected_doctor']['full_name'] ?? 'الطبيب');
                    $datetime = (string) ($appointment['appointment_datetime'] ?? '');
                    $id = (string) ($appointment['appointment_id'] ?? '');
                    $draft = "تم تأكيد الكود وحجز الموعد مع {$doctor} بتاريخ {$datetime}. رقم الحجز: {$id}.";
                    return $this->finish($state, 'appointment_booked', ['appointment' => $appointment, 'quick_replies' => []], $draft, $message);
                } catch (Throwable $e) {
                    $booking['stage'] = 'need_time';
                    $booking['verification_status'] = 'failed';
                    $state['booking'] = $booking;
                    $draft = 'وصل الكود صحيح، لكن ما قدرت أثبت الموعد لأن الوقت صار غير متاح أو البيانات ناقصة. احكيلي وقت ثاني مناسب لنفس التاريخ.';
                    return $this->finish($state, 'booking_create_failed', ['error' => $e->getMessage(), 'quick_replies' => []], $draft, $message);
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
            return $this->finish($state, 'booking_otp_invalid', ['reason' => $reason, 'quick_replies' => []], $draft, $message);
        }

        $booking = $this->applyBookingUpdates($message, $booking);

        if ($this->isBookingSideQuestion($message, $booking)) {
            $side = $this->answerSideQuestion($message);
            if ($side !== null) {
                $state['booking'] = $booking;
                $draft = $side . "\n" . $this->currentBookingQuestion($booking);
                return $this->finish($state, 'booking_side_question', ['booking' => $this->publicBooking($booking), 'quick_replies' => []], $draft, $message);
            }
        }

        $next = $this->nextBookingStep($booking, $message);
        $state['booking'] = $next['booking'];

        return $this->finish(
            $state,
            (string) $next['intent'],
            (array) ($next['data'] ?? ['quick_replies' => []]),
            (string) $next['draft'],
            $message
        );
    }

    private function applyBookingUpdates(string $message, array $booking): array
    {
        if ($this->tools->isChangeDoctorRequest($message)) {
            $booking['selected_doctor'] = null;
            $booking['candidate_doctors'] = [];
            $booking['selected_time'] = null;
            $booking['stage'] = 'need_doctor';
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
            $matches = !empty($candidates)
                ? $this->tools->matchDoctorFromCandidates($message, $candidates)
                : $this->tools->searchDoctors($message, isset($booking['selected_department']['department_id']) ? (int) $booking['selected_department']['department_id'] : null);

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

        if (($booking['stage'] ?? '') === 'need_reason' && empty($booking['reason']) && $this->tools->looksLikeReason($message)) {
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
            $names = $this->formatDoctorNames($booking['candidate_doctors']);
            return [
                'booking' => $booking,
                'intent' => 'booking_choose_doctor',
                'data' => ['doctors' => $booking['candidate_doctors'], 'quick_replies' => []],
                'draft' => "لقيت أكثر من طبيب مناسب: {$names}. أي طبيب تقصد؟ اكتب الاسم الأول أو جزء من الاسم.",
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
                    'data' => ['doctor' => $booking['selected_doctor'], 'slots' => $availability, 'quick_replies' => []],
                    'draft' => "ما لقيت مواعيد متاحة عند {$doctor} بتاريخ {$date}. اكتب تاريخ ثاني وبفحصه لك.",
                ];
            }

            if (!empty($booking['selected_time']) && !in_array($booking['selected_time'], $booking['last_slots'], true)) {
                $shown = implode('، ', array_slice($booking['last_slots'], 0, 6));
                $booking['selected_time'] = null;
                $booking['stage'] = 'need_time';
                return [
                    'booking' => $booking,
                    'intent' => 'booking_time_unavailable',
                    'data' => ['slots' => $availability, 'quick_replies' => []],
                    'draft' => "الوقت اللي اخترته مش متاح. المتاح في هذا اليوم: {$shown}. أي وقت بناسبك؟",
                ];
            }
        }

        if (empty($booking['selected_time'])) {
            $booking['stage'] = 'need_time';
            $booking['last_missing_field'] = 'time';
            $shown = implode('، ', array_slice($booking['last_slots'] ?? [], 0, 6));
            $doctor = (string) ($booking['selected_doctor']['full_name'] ?? 'الطبيب');
            $date = (string) $booking['selected_date'];
            return [
                'booking' => $booking,
                'intent' => 'booking_choose_time',
                'data' => ['doctor' => $booking['selected_doctor'], 'slots' => $booking['last_slots'] ?? [], 'quick_replies' => []],
                'draft' => "الأوقات المتاحة مع {$doctor} بتاريخ {$date}: {$shown}. أي ساعة أعتمد؟",
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

        if (empty($booking['patient_email'])) {
            $booking['stage'] = 'need_email';
            $booking['last_missing_field'] = 'email';
            return [
                'booking' => $booking,
                'intent' => 'booking_need_email',
                'data' => ['booking' => $this->publicBooking($booking), 'quick_replies' => []],
                'draft' => 'آخر خطوة قبل التثبيت: اكتب إيميلك حتى أرسل رمز تأكيد الحجز.',
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
            $debug = $otp['send_result']['debug_code'] ?? null;
            $draft = "أرسلت رمز التأكيد على {$masked}. اكتب الرمز هون حتى أثبت الموعد.";
            if ($debug !== null) {
                $draft .= " في بيئة التطوير المحلية، رمز التجربة هو: {$debug}.";
            }

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
        $departments = $this->tools->searchDepartments($message);
        if (empty($departments)) {
            $departments = array_slice($this->tools->getDepartments(), 0, 6);
        }

        $state['goal'] = 'department_info';
        $state['intent'] = 'ask_departments';
        $lines = array_map(static function (array $department): string {
            $name = (string) ($department['name'] ?? 'قسم');
            $location = (string) ($department['location'] ?? '');
            $hours = (string) ($department['working_hours'] ?? '');
            return trim($name . ($location !== '' ? '، الموقع: ' . $location : '') . ($hours !== '' ? '، الدوام: ' . $hours : ''));
        }, array_slice($departments, 0, 5));

        $draft = empty($lines)
            ? 'ما لقيت قسم مطابق بالضبط. اكتب اسم القسم أو الخدمة اللي بدك إياها.'
            : 'هاي المعلومات الأقرب من النظام: ' . implode(' | ', $lines) . '.';

        return $this->finish($state, 'ask_departments', ['departments' => $departments, 'quick_replies' => []], $draft, $message);
    }

    private function handleDoctorQuestion(string $message, array $state): array
    {
        $doctors = $this->tools->searchDoctors($message);
        $state['goal'] = 'doctor_info';
        $state['intent'] = 'ask_doctors';

        if (empty($doctors)) {
            $draft = 'ما لقيت طبيب مطابق بالضبط. اكتب اسم الطبيب أو التخصص، مثل قلب، عيون، أطفال، أو باطنية.';
        } else {
            $lines = array_map(static function (array $doctor): string {
                return (string) ($doctor['full_name'] ?? 'طبيب') . '، ' . (string) ($doctor['specialty'] ?? 'تخصص غير محدد');
            }, array_slice($doctors, 0, 5));
            $draft = 'الأطباء الأقرب لطلبك: ' . implode(' | ', $lines) . '. إذا بدك أحجز مع أحدهم، احكيلي موعد مناسب.';
        }

        return $this->finish($state, 'ask_doctors', ['doctors' => $doctors, 'quick_replies' => []], $draft, $message);
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
            $draft = "حسب المعلومات المتوفرة عندي: {$ragContext}\nإذا بدك، احكيلي شو المطلوب تحديدا حتى أوجهك للخطوة المناسبة.";
            return $this->finish($state, 'hospital_knowledge', ['rag_context' => $ragContext, 'quick_replies' => []], $draft, $message);
        }

        $draft = 'أنا معك. اكتبلي طلبك بشكل مباشر: حجز موعد، اسم طبيب، قسم، خدمة، أو أعراض وبدك توجيه عام.';
        return $this->finish($state, 'fallback', ['quick_replies' => []], $draft, $message);
    }

    private function answerSideQuestion(string $message): ?string
    {
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
                return 'ما لقيت طبيب مطابق لسؤالك الجانبي.';
            }
            return 'الأقرب لسؤالك: ' . $this->formatDoctorNames(array_slice($doctors, 0, 3)) . '.';
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
        if (empty($booking['patient_email'])) {
            return 'نكمل الحجز: اكتب إيميلك حتى أرسل رمز التأكيد.';
        }
        if (($booking['verification_status'] ?? '') === 'sent') {
            return 'نكمل الحجز: اكتب رمز التأكيد من الإيميل.';
        }

        return 'نكمل الحجز؟';
    }

    private function isBookingSideQuestion(string $message, array $booking): bool
    {
        if (($booking['stage'] ?? '') === 'need_doctor' || empty($booking['selected_doctor'])) {
            return false;
        }
        if ($this->tools->extractDate($message) !== null || $this->tools->extractTime($message) !== null || $this->tools->extractEmail($message) !== null) {
            return false;
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
            ],
            'tool_result' => $data,
        ], $allowLlm);

        $state = $this->stateManager->appendTurn($state, 'bot', $reply);
        $this->stateManager->save($state);

        $data['conversation_state'] = [
            'goal' => $state['goal'] ?? 'general',
            'booking' => $this->publicBooking($state['booking'] ?? []),
        ];
        $data['quick_replies'] = [];

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
            'verification_status' => $booking['verification_status'] ?? 'not_started',
            'last_missing_field' => $booking['last_missing_field'] ?? null,
            'candidate_doctors_count' => count($booking['candidate_doctors'] ?? []),
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

    private function isResetRequest(string $message): bool
    {
        $normalized = $this->tools->normalize($message);
        return in_array($normalized, ['reset', 'restart'], true)
            || $this->containsAny($normalized, ['ابدا من جديد', 'ابدأ من جديد', 'صفر المحادثه', 'صفر المحادثة']);
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
