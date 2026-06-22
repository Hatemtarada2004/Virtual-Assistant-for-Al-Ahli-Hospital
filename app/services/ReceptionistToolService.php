<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/DepartmentRepository.php';
require_once __DIR__ . '/../repositories/DoctorRepository.php';
require_once __DIR__ . '/../repositories/LabTestRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/ArabicPatientTextNormalizerService.php';
require_once __DIR__ . '/EmailOtpService.php';
require_once __DIR__ . '/HospitalWebsiteKnowledgeService.php';
require_once __DIR__ . '/RagRetrievalService.php';
require_once __DIR__ . '/ServiceCatalogService.php';

class ReceptionistToolService
{
    private DepartmentRepository $departments;
    private DoctorRepository $doctors;
    private LabTestRepository $labTests;
    private PatientRepository $patients;
    private AppointmentService $appointments;
    private EmailOtpService $emailOtp;
    private RagRetrievalService $rag;
    private HospitalWebsiteKnowledgeService $websiteKnowledge;
    private ArabicPatientTextNormalizerService $normalizer;
    private ServiceCatalogService $services;

    public function __construct()
    {
        $this->departments = new DepartmentRepository();
        $this->doctors = new DoctorRepository();
        $this->labTests = new LabTestRepository();
        $this->patients = new PatientRepository();
        $this->appointments = new AppointmentService();
        $this->emailOtp = new EmailOtpService();
        $this->rag = new RagRetrievalService();
        $this->websiteKnowledge = new HospitalWebsiteKnowledgeService();
        $this->normalizer = new ArabicPatientTextNormalizerService();
        $this->services = new ServiceCatalogService();
    }

    public function getDepartments(): array
    {
        return array_map(fn($department) => $department->toArray(), $this->departments->findAll());
    }

    public function searchDepartments(string $query): array
    {
        $query = trim($query);
        $departments = $this->getDepartments();
        if ($query === '') {
            return $departments;
        }

        $normalized = $this->normalize($query);
        $scored = [];
        foreach ($departments as $department) {
            $score = $this->scoreText($normalized, [
                $department['name'] ?? '',
                $department['location'] ?? '',
                $department['working_hours'] ?? '',
            ]);
            $score += $this->departmentAliasScore($normalized, $department);
            if ($score > 0) {
                $department['_score'] = $score;
                $scored[] = $department;
            }
        }

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
        return array_map(function (array $department): array {
            unset($department['_score']);
            return $department;
        }, array_slice($scored, 0, 6));
    }

    public function searchDoctors(string $query, ?int $departmentId = null): array
    {
        $normalized = $this->normalize($query);
        $doctors = array_map(fn($doctor) => $doctor->toArray(), $this->doctors->findAll());
        if ($departmentId !== null) {
            $doctors = array_values(array_filter($doctors, static fn(array $doctor) => (int) ($doctor['department_id'] ?? 0) === $departmentId));
        }

        // لو القسم محدد وما في استعلام نصي → أرجع كل أطباء القسم (لتخزينهم كـ candidates)
        if ($normalized === '') {
            return $departmentId !== null ? $doctors : array_slice($doctors, 0, 6);
        }

        $scored = [];
        foreach ($doctors as $doctor) {
            $score = $this->doctorNameScore($normalized, $doctor);
            $score += $this->scoreText($normalized, [
                $doctor['full_name'] ?? '',
                $doctor['specialty'] ?? '',
                $doctor['department_name'] ?? '',
            ]);
            $score += $this->specialtyAliasScore($normalized, $doctor);
            if ($departmentId !== null) {
                $score += 2;
            }

            if ($score > 0) {
                $doctor['_score'] = $score;
                $scored[] = $doctor;
            }
        }

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $best = (int) ($scored[0]['_score'] ?? 0);
        if ($best >= 8) {
            $scored = array_values(array_filter($scored, static fn(array $doctor) => (int) $doctor['_score'] >= max(5, $best - 2)));
        }

        // لو القسم محدد → رجّع كل الأطباء المطابقين (بدون قطع) حتى يكونوا كلهم كـ candidates
        $maxResults = $departmentId !== null ? 20 : 6;
        return array_map(function (array $doctor): array {
            unset($doctor['_score']);
            return $doctor;
        }, array_slice($scored, 0, $maxResults));
    }

    public function doctorBiography(array $doctor): string
    {
        $name = (string) ($doctor['full_name'] ?? 'الطبيب');
        $specialty = (string) ($doctor['specialty'] ?? 'تخصص غير محدد');
        $department = (string) ($doctor['department_name'] ?? 'قسم غير محدد');
        $phone = (string) ($doctor['phone'] ?? '');
        $email = (string) ($doctor['email'] ?? '');
        $services = [];

        if (!empty($doctor['department_id'])) {
            foreach (array_slice($this->getServicesByDepartment((int) $doctor['department_id']), 0, 4) as $service) {
                if (is_array($service) && !empty($service['name'])) {
                    $services[] = (string) $service['name'];
                }
            }
        }

        $parts = [
            "{$name} يعمل ضمن {$department}، وتخصصه {$specialty}.",
        ];

        if (!empty($services)) {
            $parts[] = 'من الخدمات المرتبطة بالقسم: ' . implode('، ', $services) . '.';
        }
        if ($phone !== '') {
            $parts[] = "رقم التواصل المسجل: {$phone}.";
        }
        if ($email !== '') {
            $parts[] = "البريد المسجل: {$email}.";
        }

        return implode(' ', $parts);
    }

    public function getLabResultSummary(int $patientId): string
    {
        if ($patientId <= 0) {
            return 'بخصوص نتائج التحاليل — لازم تسجل دخول حتى أقدر أطلعها لك.';
        }
        $pending = $this->labTests->findByPatientIdAndStatus($patientId, 'Pending');
        $ready   = $this->labTests->findByPatientIdAndStatus($patientId, 'Ready');
        if (empty($pending) && empty($ready)) {
            return 'بخصوص التحاليل — ما لقيت فحوصات مسجلة باسمك.';
        }
        $parts = [];
        if (!empty($ready)) {
            $names = implode('، ', array_map(fn($t) => $t->toArray()['test_name'] ?? '', array_slice($ready, 0, 3)));
            $parts[] = count($ready) . ' نتيجة جاهزة: ' . $names;
        }
        if (!empty($pending)) {
            $parts[] = count($pending) . ' فحص لا يزال معلقاً.';
        }
        return 'بخصوص تحاليلك — ' . implode(' | ', $parts) . ' سأكمل معك الحجز.';
    }

    public function getPatientLabTests(int $patientId, ?string $status = null): array
    {
        $tests = $status === 'Ready'
            ? $this->labTests->findByPatientIdAndStatus($patientId, 'Ready')
            : ($status === 'Pending'
                ? $this->labTests->findByPatientIdAndStatus($patientId, 'Pending')
                : $this->labTests->findByPatientId($patientId));

        return array_map(fn($test) => $test->toArray(), $tests);
    }

    public function searchPatientLabTests(int $patientId, string $query, ?string $status = null): array
    {
        $tests = $this->labTests->searchByPatientId($patientId, $this->labSearchQuery($query), $status);
        if (empty($tests)) {
            $tests = $status === null
                ? $this->labTests->findByPatientId($patientId)
                : $this->labTests->findByPatientIdAndStatus($patientId, $status);
        }

        return array_map(fn($test) => $test->toArray(), array_slice($tests, 0, 5));
    }

    public function findPatientByNationalId(string $nationalId): ?array
    {
        $nationalId = preg_replace('/\D+/', '', $this->normalizeDigits($nationalId)) ?? '';
        if ($nationalId === '') {
            return null;
        }

        $patient = $this->patients->findByNationalId($nationalId);
        return $patient !== null ? $patient->toArray() : null;
    }

    public function matchDoctorFromCandidates(string $query, array $candidates): array
    {
        $normalized = $this->normalize($query);
        if ($normalized === '') {
            return [];
        }

        $scored = [];
        foreach ($candidates as $doctor) {
            if (!is_array($doctor)) {
                continue;
            }
            $score = $this->doctorNameScore($normalized, $doctor);
            $score += $this->specialtyAliasScore($normalized, $doctor);
            if ($score > 0) {
                $doctor['_score'] = $score;
                $scored[] = $doctor;
            }
        }

        usort($scored, fn($a, $b) => $b['_score'] <=> $a['_score']);
        $best = (int) ($scored[0]['_score'] ?? 0);
        if ($best > 0) {
            $scored = array_values(array_filter($scored, static fn(array $doctor) => (int) $doctor['_score'] >= $best));
        }

        return array_map(function (array $doctor): array {
            unset($doctor['_score']);
            return $doctor;
        }, array_slice($scored, 0, 3));
    }

    public function getDoctorAvailability(int $doctorId, string $date, ?string $period = null): array
    {
        $slots = $this->appointments->getAvailableSlots($doctorId, $date);
        if ($period !== null && !empty($slots['available_slots'])) {
            $slots['available_slots'] = array_values(array_filter(
                $slots['available_slots'],
                fn(string $slot) => $this->slotMatchesPeriod($slot, $period)
            ));
            $slots['total_available'] = count($slots['available_slots']);
        }

        return $slots;
    }

    public function sendVerificationCode(array $booking): array
    {
        $email = (string) ($booking['patient_email'] ?? '');
        $code = $this->emailOtp->generateCode();
        $sent = $this->emailOtp->sendBookingCode($email, $code, [
            'doctor_name' => (string) ($booking['selected_doctor']['full_name'] ?? ''),
            'appointment_datetime' => trim((string) ($booking['selected_date'] ?? '') . ' ' . (string) ($booking['selected_time'] ?? '')),
        ]);

        return [
            'email' => $email,
            'otp_hash' => $this->hashCode($code),
            'otp_expires_at' => time() + 600,
            'send_result' => $sent,
        ];
    }

    public function sendLabResultCode(array $labResult): array
    {
        $email = (string) ($labResult['patient_email'] ?? '');
        $code = $this->emailOtp->generateCode();
        $sent = $this->emailOtp->sendLabResultCode($email, $code, [
            'patient_name' => (string) ($labResult['verified_patient']['full_name'] ?? ''),
            'test_names' => array_values(array_filter(array_map(
                static fn(array $test): string => (string) ($test['test_name'] ?? ''),
                (array) ($labResult['selected_tests'] ?? [])
            ))),
        ]);

        return [
            'email' => $email,
            'otp_hash' => $this->hashCode($code),
            'otp_expires_at' => time() + 600,
            'send_result' => $sent,
        ];
    }

    public function sendLabResultsEmail(array $labResult): array
    {
        return $this->emailOtp->sendLabResults(
            (string) ($labResult['patient_email'] ?? ''),
            (array) ($labResult['selected_tests'] ?? []),
            (array) ($labResult['verified_patient'] ?? [])
        );
    }

    public function sendAppointmentConfirmation(array $booking, array $appointment): array
    {
        return $this->emailOtp->sendAppointmentConfirmation(
            (string) ($booking['patient_email'] ?? ''),
            $appointment,
            $booking
        );
    }

    public function verifyCode(string $message, array $booking): array
    {
        $code = $this->extractVerificationCode($message);
        if ($code === null) {
            return ['ok' => false, 'reason' => 'missing_code'];
        }

        if ((int) ($booking['otp_expires_at'] ?? 0) < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $hash = (string) ($booking['otp_hash'] ?? '');
        if ($hash === '' || !hash_equals($hash, $this->hashCode($code))) {
            return ['ok' => false, 'reason' => 'invalid'];
        }

        return ['ok' => true, 'code' => $code];
    }

    public function createOrGetPatient(string $email, ?string $name = null, ?string $nationalId = null, ?string $phone = null): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $nationalId = preg_replace('/\D+/', '', $this->normalizeDigits((string) $nationalId)) ?? '';

        if ($nationalId !== '') {
            $patient = $this->patients->findByNationalId($nationalId);
            if ($patient !== null) {
                return $patient->toArray();
            }
        }

        $patient = $this->patients->findByEmail($email);
        if ($patient !== null && $nationalId === '') {
            return $patient->toArray();
        }

        $id = $nationalId !== ''
            ? $this->patients->createFromChatBooking($email, $name, $nationalId, $phone)
            : $this->patients->createFromChatEmail($email, $name);
        $patient = $this->patients->findById($id);
        if ($patient === null) {
            throw new RuntimeException('Patient was created but could not be loaded.');
        }

        return $patient->toArray();
    }

    public function createAppointment(array $booking): array
    {
        $doctor = $booking['selected_doctor'] ?? null;
        if (!is_array($doctor)) {
            throw new InvalidArgumentException('Doctor is missing.');
        }

        $patient = is_array($booking['verified_patient'] ?? null)
            ? $booking['verified_patient']
            : $this->findPatientByNationalId((string) ($booking['patient_national_id'] ?? ''));
        if (!is_array($patient) || empty($patient['patient_id'])) {
            throw new InvalidArgumentException('Patient national ID is not verified.');
        }

        return $this->appointments->book([
            'patient_id' => (int) $patient['patient_id'],
            'doctor_id' => (int) $doctor['doctor_id'],
            'department_id' => (int) $doctor['department_id'],
            'appointment_datetime' => (string) $booking['selected_date'] . ' ' . (string) $booking['selected_time'] . ':00',
            'reason' => (string) ($booking['reason'] ?? 'حجز عبر الشات بوت'),
        ]);
    }

    public function getPatientAppointments(int $patientId): array
    {
        return $this->appointments->getByPatient($patientId);
    }

    public function getUpcomingBookedAppointments(int $patientId): array
    {
        $appointments = $this->getPatientAppointments($patientId);
        $now = time();

        return array_values(array_filter($appointments, static function (array $appointment) use ($now): bool {
            $when = strtotime((string) ($appointment['appointment_datetime'] ?? ''));
            return ($appointment['status'] ?? '') === 'Booked' && $when !== false && $when >= $now;
        }));
    }

    public function cancelAppointment(int $appointmentId, int $patientId): array
    {
        return $this->appointments->cancel($appointmentId, $patientId);
    }

    public function rescheduleAppointment(int $appointmentId, int $patientId, string $date, string $time): array
    {
        return $this->appointments->reschedule($appointmentId, $patientId, $date . ' ' . $time . ':00');
    }

    public function searchHospitalKnowledge(string $query): string
    {
        $context = $this->websiteKnowledge->contextForPrompt($query);
        if ($context !== '') {
            return $context;
        }

        return $this->rag->contextForPrompt($query);
    }

    public function getServices(): array
    {
        return $this->services->getAll();
    }

    public function getServicesByDepartment(int $departmentId): array
    {
        return $this->services->getByDepartment($departmentId);
    }

    public function searchServices(string $query): array
    {
        $services = $this->getServices();
        $normalized = $this->normalize($query);
        if ($normalized === '' || $this->containsAny($normalized, ['خدمات', 'الخدمات', 'ايش الخدمات', 'شو الخدمات'])) {
            return array_slice($services, 0, 8);
        }

        $scored = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $score = $this->scoreText($normalized, [
                $service['name'] ?? '',
                $service['description'] ?? '',
                $service['department_name'] ?? '',
                isset($service['base_cost']) ? (string) $service['base_cost'] : '',
            ]);

            if ($score > 0) {
                $service['_score'] = $score;
                $scored[] = $service;
            }
        }

        usort($scored, static fn(array $a, array $b): int => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
        return array_map(static function (array $service): array {
            unset($service['_score']);
            return $service;
        }, array_slice($scored, 0, 6));
    }

    public function isAppointmentInquiry(string $message): bool
    {
        $normalized = $this->normalize($message);
        $hasInquiry = $this->containsAny($normalized, [
            'استفسر', 'استفسار', 'استفشر', 'استفشار', 'استفاسر', 'استفاسير',
            'شو موعدي', 'متى موعدي', 'وين موعدي', 'امتى موعدي', 'ايمتى موعدي',
            'عندي موعد', 'شو حجزي', 'اعرف موعدي', 'اشوف موعدي', 'ابي اشوف موعدي',
            'عرض مواعيد', 'مواعيدي', 'حجوزاتي', 'شو عندي', 'ايش موعدي',
            'لما موعدي', 'كيف اعرف موعدي', 'شو موعد', 'اعرفني موعدي',
            'بدي اعرف موعدي', 'بدي اشوف موعدي', 'فين موعدي',
        ]);
        $hasAppointmentWord = $this->containsAny($normalized, ['موعد', 'حجز', 'موعدي', 'حجزي', 'مواعيد']);
        $hasCancelOrEdit = $this->containsAny($normalized, ['الغ', 'اغير', 'اعدل', 'تعديل', 'إلغاء']);
        return ($hasInquiry || ($hasAppointmentWord && $this->containsAny($normalized, ['استفسر', 'استفسار', 'اعرف', 'اشوف', 'عرض', 'شو', 'ايش', 'متى', 'امتى', 'لما', 'ايمتى'])))
            && !$hasCancelOrEdit
            && !$this->isBookingRequest($message)
            && !$this->isLabResultQuestion($message);
    }

    public function isExistingAppointmentChangeRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        $hasCancelWord = $this->containsAny($normalized, ['الغاء', 'إلغاء', 'الغي', 'اتلغ', 'الغ موعد']);
        $hasRescheduleWord = $this->containsAny($normalized, ['اغير', 'تغيير', 'اعدل', 'تعديل', 'ابدل']);
        $hasAppointmentWord = $this->containsAny($normalized, ['موعد', 'حجز', 'موعدي', 'حجزي']);
        return ($hasCancelWord || $hasRescheduleWord) && $hasAppointmentWord;
    }

    public function isAppointmentCancelRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, ['الغاء', 'إلغاء', 'الغي', 'اتلغ'])
            && $this->containsAny($normalized, ['موعد', 'حجز', 'موعدي', 'حجزي']);
    }

    public function extractAppointmentId(string $message): ?int
    {
        $text = $this->normalizeDigits($message);
        if (preg_match(
            '/(?:رقم\s*الحجز|رقم\s*الموعد|الحجز\s*رقم|موعد\s*رقم|حجز\s*رقم|الحجز|موعد\s+الحجز)\s*#?\s*([0-9]{1,6})(?!\d)/u',
            $text,
            $m
        )) {
            return (int) $m[1];
        }

        return null;
    }

    public function extractPhone(string $message): ?string
    {
        $text = $this->normalizeDigits($message);
        if (preg_match('/(?<!\d)(0[5-9][0-9]{8})(?!\d)/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    public function extractEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m)) {
            return mb_strtolower($m[0], 'UTF-8');
        }

        return null;
    }

    public function extractNationalId(string $message): ?string
    {
        $message = $this->normalizeDigits($message);
        if (preg_match_all('/(?<!\d)([0-9]{7,12})(?!\d)/', $message, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (strlen($candidate) === 6) {
                    continue; // OTP code, not a national ID
                }
                // Palestinian/Jordanian phone numbers: 10 digits starting with 0 (05x, 02x, etc.)
                if (preg_match('/^0[2-9][0-9]{8}$/', $candidate)) {
                    continue;
                }
                return $candidate;
            }
        }

        return null;
    }

    public function diagnosePhoneError(string $message): ?string
    {
        $text = $this->normalizeDigits($message);
        preg_match_all('/[0-9]{3,15}/', $text, $matches);
        foreach ($matches[0] as $seq) {
            $len = strlen($seq);
            if ($len === 6) {
                continue; // OTP code
            }
            if ($len >= 7 && !str_starts_with($seq, '0')) {
                continue; // likely a national ID
            }
            if ($len < 5) {
                continue;
            }
            if ($len < 10) {
                return "رقم الهاتف اللي كتبته فيه {$len} أرقام بس — لازم يكون 10 أرقام كاملة. مثال: 0591234567.";
            }
            if ($len > 10) {
                return "رقم الهاتف اللي كتبته فيه {$len} رقم — اللازم 10 أرقام بالضبط. تأكد وأعد الكتابة.";
            }
            if (!preg_match('/^0[5-9]/', $seq)) {
                return "رقم الهاتف لازم يبدأ بـ 05 أو 06 أو 07 أو 08. اكتب الرقم من جديد (مثال: 0591234567).";
            }
        }

        return null;
    }

    public function diagnoseEmailError(string $message): ?string
    {
        if (str_contains($message, '@') && $this->extractEmail($message) === null) {
            return "الإيميل اللي كتبته مش صحيح — تأكد من الصيغة مثل: user@gmail.com. اكتب الإيميل من جديد.";
        }

        return null;
    }

    public function diagnoseNationalIdError(string $message): ?string
    {
        $text = $this->normalizeDigits($message);
        preg_match_all('/[0-9]+/', $text, $matches);
        foreach ($matches[0] as $seq) {
            $len = strlen($seq);
            if ($len === 6) {
                continue; // OTP
            }
            if (preg_match('/^0[2-9][0-9]{8}$/', $seq)) {
                continue; // phone number
            }
            if ($len >= 3 && $len < 7) {
                return "رقم الهوية اللي كتبته قصير — لازم يكون بين 7 و 12 رقم. اكتب رقم الهوية الصحيح.";
            }
            if ($len > 12) {
                return "رقم الهوية اللي كتبته فيه {$len} رقم — اللازم بين 7 و 12 رقم. تأكد وأعد الكتابة.";
            }
        }

        return null;
    }

    public function findPatientByPhone(string $phone): ?array
    {
        $phone = trim($this->normalizeDigits($phone));
        if ($phone === '') {
            return null;
        }

        $patient = $this->patients->findByPhone($phone);
        return $patient !== null ? $patient->toArray() : null;
    }

    public function extractVerificationCode(string $message): ?string
    {
        $message = $this->normalizeDigits($message);
        if (preg_match('/\b([0-9]{6})\b/', $message, $m)) {
            return $m[1];
        }

        return null;
    }

    public function extractDate(string $message): ?string
    {
        $normalized = $this->normalize($message);

        if (str_contains($normalized, 'بعد بكرا') || str_contains($normalized, 'بعد غدا')) {
            return date('Y-m-d', strtotime('+2 days'));
        }
        if (str_contains($normalized, 'بكرا') || str_contains($normalized, 'غدا')) {
            return date('Y-m-d', strtotime('+1 day'));
        }
        if (str_contains($normalized, 'اليوم')) {
            return date('Y-m-d');
        }
        if (str_contains($normalized, 'الاسبوع القادم') || str_contains($normalized, 'الاسبوع الجاي')) {
            return date('Y-m-d', strtotime('+7 days'));
        }

        $text = $this->normalizeDigits($message);
        if (preg_match('/\b(20[0-9]{2})[-\/](0?[1-9]|1[0-2])[-\/](0?[1-9]|[12][0-9]|3[01])\b/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/\b(0?[1-9]|[12][0-9]|3[01])[-\/](0?[1-9]|1[0-2])[-\/](20[0-9]{2})\b/', $text, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    public function extractTime(string $message): ?string
    {
        $text = $this->normalizeDigits($message);
        $normalized = $this->normalize($text);

        if (preg_match('/\b([01]?[0-9]|2[0-3]):([0-5][0-9])\b/', $text, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        if (preg_match('/(?:الساعه|الساعة|وقت|على|عند|ع)\s*([0-9]{1,2})(?::([0-5][0-9]))?\s*(ص|صباح|مساء|م|pm|am)?/iu', $text, $m)) {
            $hour = (int) $m[1];
            $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $suffix = isset($m[3]) ? mb_strtolower((string) $m[3], 'UTF-8') : '';
            if (($suffix === 'م' || $suffix === 'مساء' || $suffix === 'pm') && $hour < 12) {
                $hour += 12;
            }
            if (($suffix === 'ص' || $suffix === 'صباح' || $suffix === 'am') && $hour === 12) {
                $hour = 0;
            }
            if ($hour >= 0 && $hour <= 23) {
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }

        if (preg_match('/\b(9|10|11|12|13|14|15)\b/u', $normalized, $m) && $this->containsAny($normalized, ['موعد', 'يناسب', 'خليه', 'اختار'])) {
            return sprintf('%02d:00', (int) $m[1]);
        }

        return null;
    }

    public function extractPeriod(string $message): ?string
    {
        $normalized = $this->normalize($message);
        if ($this->containsAny($normalized, ['الصبح', 'صباح', 'بدري'])) {
            return 'morning';
        }
        if ($this->containsAny($normalized, ['الظهر', 'بعد الظهر', 'العصر', 'مساء'])) {
            return 'afternoon';
        }

        return null;
    }

    public function isBookingRequest(string $message): bool
    {
        $plain = mb_strtolower($message, 'UTF-8');
        if ((str_contains($plain, "\u{0645}\u{0648}\u{0639}\u{062F}") || str_contains($plain, "\u{0627}\u{062D}\u{062C}\u{0632}") || str_contains($plain, "\u{062D}\u{062C}\u{0632}") || str_contains($plain, "\u{0627}\u{0643}\u{0634}\u{0641}"))
            && !str_contains($plain, "\u{0627}\u{0644}\u{063A}\u{064A}")
            && !str_contains($plain, "\u{0627}\u{0644}\u{063A}\u{0627}\u{0621}")
            && !str_contains($plain, "\u{0625}\u{0644}\u{063A}\u{0627}\u{0621}")
            && !str_contains($plain, "\u{0645}\u{0648}\u{0639}\u{062F}\u{064A}")) {
            return true;
        }
        if (($this->containsAny($this->normalize($plain), ['الغاء', 'إلغاء', 'الغي', 'تعديل موعدي', 'موعدي']) === false)
            && (str_contains($plain, 'موعد') || str_contains($plain, 'احجز') || str_contains($plain, 'أحجز') || str_contains($plain, 'حجز') || str_contains($plain, 'اكشف'))) {
            return true;
        }

        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'اريد موعد',
            'أريد موعد',
            'بدي موعد',
            'موعد مع',
            'احجز',
            'حجز',
            'موعد',
            'بدي عند',
            'اريد عند',
            'اكشف',
            'مراجعه عند',
            'ثبتلي',
            'اعمل موعد',
        ]) && !$this->containsAny($normalized, ['الغاء', 'الغي', 'تعديل موعدي', 'موعدي']);
    }

    public function isDoctorQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'دكتور',
            'طبيب',
            'اطباء',
            'دكاتره',
            'قلب',
            'عيون',
            'باطن',
            'اطفال',
            'نسائي',
            'جراح',
            'اشعه',
            'هضم',
            'اعصاب',
            'عصاب',
            'عصبي',
            'عظام',
            'مفاصل',
            'جلد',
            'انف',
            'حنجره',
            'مسالك',
        ]);
    }

    public function isDoctorBioQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
                'سيره', 'سيرة', 'نبذه', 'نبذة',
                'معلومات عن', 'احكيلي عن', 'عرفني على',
                'مين هو', 'مين هي', 'بروفايل', 'cv',
                // Palestinian dialect: "what does he do / what's his specialty"
                'شو بيشتغل', 'شو يشتغل', 'ايش بيشتغل', 'ايش يشتغل',
                'شو بيعمل', 'شو يعمل', 'شغلتو', 'شغلته', 'شغلتها',
                'تخصصو', 'تخصصه', 'تخصصها', 'شو تخصص',
                'تخصص الدكتور', 'ايش تخصص',
            ]);
    }

    public function isLabResultQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'نتائج الفحوصات',
            'نتيجة الفحوصات',
            'نتيجه الفحوصات',
            'نتائج التحاليل',
            'نتيجة التحليل',
            'نتيجه التحليل',
            'نتيجة فحص',
            'نتيجه فحص',
            'نتيجة الفحص',
            'نتيجه الفحص',
            'استفسر عن نتيجة',
            'استفسار عن نتيجة',
            'استفسر عن نتيجه',
            'استفسار عن نتيجه',
            'استفسر عن تيجه',
            'استفسار عن تيجه',
            'استفسر عن تيجة',
            'استفسار عن تيجة',
            'تيجه الفحص',
            'تيجة الفحص',
            'تيجتي',
            'تيجه التحليل',
            'تيجة التحليل',
            'طلعت النتيجة',
            'طلعت النتيجه',
            'طلعت التيجه',
            'طلعت التيجة',
            'بدي النتيجة',
            'بدي النتيجه',
            'بدي التيجه',
            'بدي التيجة',
            'اعطيني النتيجة',
            'اعطيني النتيجه',
            'اعطيني التيجه',
            'اعطيني التيجة',
            'فحوصاتي',
            'تحاليل',
            'تحاليلي',
            'فحص الدم',
            'فحص دم',
            'cbc',
            'lab',
        ]);
    }

    public function requestedLabStatus(string $message): ?string
    {
        $normalized = $this->normalize($message);
        if ($this->containsAny($normalized, ['جاهزه', 'جاهزة', 'طلعت', 'صدرت', 'ready'])) {
            return 'Ready';
        }
        if ($this->containsAny($normalized, ['قيد الانتظار', 'لسا', 'لم تصدر', 'pending'])) {
            return 'Pending';
        }

        return null;
    }

    public function isDepartmentQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'قسم',
            'اقسام',
            'دوام',
            'طابق',
            'وين',
            'موقع',
            'طوارئ',
            'فرع',
        ]);
    }

    public function isHospitalKnowledgeQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'رقم حجز',
            'هاتف',
            'تلفون',
            'اتصال',
            'اتصل',
            'وين المستشفى',
            'موقع المستشفى',
            'فرش الهوى',
            'العيادات الخارجية',
            'شركات التامين',
            'شركات التأمين',
            'التامينات',
            'التأمينات',
            'مواعيد العيادات',
            'دوام العيادات',
            'تاريخ المستشفى',
            'من نحن',
            'تأسس',
            'تاسس',
            'مدير المستشفى',
            'المدير العام',
            'التكروري',
            'رؤية المستشفى',
            'رسالة المستشفى',
            'احصائيات',
            'إحصائيات',
            'تبرع',
            'تبرعات',
            'مشاريع المستشفى',
            'حساب بنكي',
            'حساب التبرع',
            'صندوق المريض',
            'المريض الفقير',
            'تعليم',
            'تدريب',
            'اقامة',
            'إقامة',
            'برنامج',
            'تخصص',
            'مركز التعليم',
            'حافظ عبد النبي',
            'حقوق المريض',
            'واجبات المريض',
            'اوراق الولادة',
            'شهادة وفاة',
            'موقف سيارات',
            'كافتيريا',
            'متجر الهدايا',
            'صالون',
            'تحويلة',
            'بحث',
            'ابحث',
            'معلومات',
            'تعليمات',
            'ارشادات',
            'إرشادات',
            'تعليمه',
            'تعليمات المريض',
            'بعد العملية',
            'بعد عملية',
            'ما بعد',
            'ما بعد العملية',
            'قبل العملية',
            'قبل عملية',
            'تحضير للعملية',
            'بعد الولادة',
            'بعد القسطرة',
            'بعد التنظير',
            'بعد الجراحة',
            'بعد عملية القلب',
            'بعد عملية قلب',
            'قلب مفتوح',
            'مفتوح',
            'زراعة صمام',
            'رعاية المريض',
            'خروج من المستشفى',
            'بعد الخروج',
            'حقوق',
            'واجبات',
            'إجراءات',
            'اجراءات',
            'قبول',
            'تنويم',
            'ادخال',
            'إدخال',
        ]);
    }

    public function isPriceQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'كم',
            'سعر',
            'رسوم',
            'تكلفه',
            'تكلفة',
            'كشفيه',
            'الكشفيه',
            'كشفيه',
            'الكشفية',
            'كشفيع',
            'حق الكشف',
        ]);
    }

    public function isServiceQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        // Doctor questions take precedence even if they mention a service-sounding word
        if ($this->isDoctorQuestion($message)) {
            return false;
        }
        return $this->isPriceQuestion($message) || $this->containsAny($normalized, [
            'خدمه',
            'خدمة',
            'خدمات',
            'فحص',
            'اشعه',
            'اشعة',
            'ايكو',
            'قسطره',
            'تنظير',
            'استشارة',
            'استشاره',
        ]);
    }

    public function isChatbotCapabilityQuestion(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
            'شو بتقدر تعمل',
            'ايش بتقدر تعمل',
            'شو خدماتك',
            'ايش خدماتك',
            'خدماتك',
            'خدمات الشات',
            'خدمات البوت',
            'شو الشات بقدم',
            'ايش الشات بقدم',
            'شو بتساعدني',
            'بشو بتساعدني',
            'ايش الخدمات الي بتقدمها',
            'ايش الخدمات اللي بتقدمها',
            'شو الخدمات اللي بتقدمها',
            'شو الخدمات يلي بتقدمها',
            'ايش الخدمات يلي بتقدمها',
            'وين بتساعد',
            'كيف بتساعدني',
            'بتقدر تساعدني بشو',
            'شو بتقدم',
            'ايش بتقدم',
            'شو قدراتك',
            'ايش قدراتك',
            'شو بتعرف تعمل',
            'ايش بتعرف تعمل',
            'شو الاشياء اللي بتساعد فيها',
            'ايش الاشياء اللي بتساعد فيها',
            'بتساعد بشو',
            'بتفيدني بشو',
            'ايش دورك',
            'شو دورك',
        ]);
    }

    public function isChangeDoctorRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, ['غير', 'بدل', 'اختار غير', 'دكتور ثاني'])
            && $this->containsAny($normalized, ['دكتور', 'طبيب']);
    }

    public function isChangeDateRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, ['غير', 'بدل', 'تاريخ ثاني', 'يوم ثاني'])
            && $this->containsAny($normalized, ['تاريخ', 'يوم', 'موعد']);
    }

    public function isChangeTimeRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, ['غير', 'بدل', 'وقت ثاني', 'ساعه ثانيه', 'ساعة ثانية'])
            && $this->containsAny($normalized, ['وقت', 'ساعه', 'ساعة']);
    }

    public function looksLikeReason(string $message): bool
    {
        $normalized = $this->normalize($message);
        if ($this->extractEmail($message) !== null || $this->extractVerificationCode($message) !== null || $this->extractNationalId($message) !== null || $this->extractPhone($message) !== null) {
            return false;
        }
        if ($this->isBookingRequest($message) || $this->isDoctorQuestion($message) || $this->isDepartmentQuestion($message)) {
            return false;
        }

        return mb_strlen($normalized, 'UTF-8') >= 3;
    }

    public function normalize(string $text): string
    {
        $text = $this->normalizer->normalizeForNlu($this->normalizeDigits($text));
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}]/u', '', $text) ?? $text;
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace('ؤ', 'و', $text);
        $text = str_replace('ئ', 'ي', $text);
        $text = str_replace('ى', 'ا', $text);
        // علامات الترقيم العربية تلتصق بالكلمة وتكسر التطابق (مثل "ناصر؟") → نحولها لمسافة
        $text = str_replace(['؟', '،', '؛', '٪', '٫', '٬', '٭', '۔'], ' ', $text);
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s:-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, session_id() !== '' ? session_id() : __FILE__);
    }

    private function labSearchQuery(string $query): string
    {
        $normalized = $this->normalize($query);
        $map = [
            'فحص دم' => 'cbc',
            'فحص الدم' => 'cbc',
            'دم' => 'cbc',
            'سكر' => 'glucose',
            'سكري' => 'hba1c',
            'قلب' => 'troponin',
            'حمل' => 'pregnancy',
            'بول' => 'urine',
            'كبد' => 'liver',
            'كلى' => 'kidney',
        ];

        foreach ($map as $needle => $replacement) {
            if (str_contains($normalized, $this->normalize($needle))) {
                return $replacement;
            }
        }

        return $normalized;
    }

    private function scoreText(string $normalizedQuery, array $texts): int
    {
        $score = 0;
        foreach ($texts as $text) {
            $normalizedText = $this->normalize((string) $text);
            if ($normalizedText === '') {
                continue;
            }
            if (str_contains($normalizedText, $normalizedQuery) || str_contains($normalizedQuery, $normalizedText)) {
                $score += 4;
            }
            foreach ($this->tokens($normalizedQuery) as $token) {
                if ($this->isStopWord($token) || mb_strlen($token, 'UTF-8') < 2) {
                    continue;
                }
                if (str_contains($normalizedText, $token)) {
                    $score += 2;
                }
            }
        }

        return $score;
    }

    private function doctorNameScore(string $normalizedQuery, array $doctor): int
    {
        $nameTokens = $this->doctorNameTokens((string) ($doctor['full_name'] ?? ''));
        if (empty($nameTokens)) {
            return 0;
        }

        $score = 0;
        foreach ($this->tokens($normalizedQuery) as $queryToken) {
            if ($this->isDoctorSelectionStopWord($queryToken) || mb_strlen($queryToken, 'UTF-8') < 2) {
                continue;
            }
            foreach ($nameTokens as $index => $nameToken) {
                if ($queryToken === $nameToken) {
                    $score += $index === 0 ? 7 : 5;
                    continue 2;
                }
                if (mb_strlen($queryToken, 'UTF-8') >= 3 && (str_contains($nameToken, $queryToken) || str_contains($queryToken, $nameToken))) {
                    $score += $index === 0 ? 5 : 3;
                    continue 2;
                }
                similar_text($queryToken, $nameToken, $percent);
                if ($percent >= 73) {
                    $score += $index === 0 ? 4 : 2;
                    continue 2;
                }
            }
        }

        return $score;
    }

    private function doctorNameTokens(string $name): array
    {
        $normalized = $this->normalize($name);
        $tokens = [];
        foreach ($this->tokens($normalized) as $token) {
            if ($this->isDoctorSelectionStopWord($token) || mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function specialtyAliasScore(string $normalized, array $doctor): int
    {
        $aliases = [
            'قلب' => ['قلب', 'صدر', 'شرايين', 'قسطرة', 'خفقان'],
            'عيون' => ['عين', 'عيون', 'نظر', 'رؤيه', 'رؤية'],
            'اطفال' => ['طفل', 'اطفال', 'ابني', 'بنتي', 'ولدي'],
            'نسائي' => ['نسائي', 'نسائيه', 'حمل', 'ولاده', 'دوره'],
            'باطن' => ['باطن', 'باطنيه', 'باطنية', 'ضغط', 'سكر'],
            'هضم' => ['هضم', 'معده', 'بطن', 'قولون', 'غثيان'],
            'اشعه' => ['اشعه', 'اشعة', 'تصوير', 'رنين', 'مقطعي'],
            'جراح' => ['جراح', 'عمليه', 'عملية', 'جرح'],
            'اعصاب' => ['اعصاب', 'عصاب', 'عصبي', 'عصبية', 'صداع', 'دوخه', 'دوخة', 'شلل', 'تنميل', 'راس', 'راسي', 'راسو', 'رأس', 'الم راس', 'وجع راس', 'نصفية', 'ضغط الدم'],
            'عظام' => ['عظام', 'عظم', 'مفاصل', 'كسر', 'ركبه', 'ركبة', 'ركبت', 'ظهر', 'ضهر', 'عمود فقري', 'غضروف', 'ورك', 'كاحل'],
            'جلد' => ['جلد', 'جلديه', 'جلدية', 'حساسيه', 'حساسية', 'طفح', 'حب شباب'],
            'انف' => ['انف', 'اذن', 'حنجره', 'حنجرة', 'بحه', 'بحة', 'التهاب حلق'],
            'مسالك' => ['مسالك', 'كليه', 'كلية', 'بول', 'حصوات'],
        ];

        $haystack = $this->normalize(($doctor['specialty'] ?? '') . ' ' . ($doctor['department_name'] ?? ''));
        $score = 0;
        foreach ($aliases as $canonical => $words) {
            $queryHasAlias = $this->containsAny($normalized, $words);
            $doctorHasAlias = str_contains($haystack, $canonical) || $this->containsAny($haystack, $words);
            if ($queryHasAlias && $doctorHasAlias) {
                $score += 6;
            }
        }

        return $score;
    }

    private function departmentAliasScore(string $normalized, array $department): int
    {
        $departmentText = $this->normalize(($department['name'] ?? '') . ' ' . ($department['location'] ?? ''));
        $score = 0;
        foreach ([
            ['قلب', 'شرايين', 'قسطرة'],
            ['اطفال', 'طفل'],
            ['عيون', 'عين', 'نظر'],
            ['نسائي', 'ولاده', 'حمل'],
            ['باطن', 'باطنيه'],
            ['هضم', 'معده'],
            ['اشعه', 'تصوير'],
            ['طوارئ', 'اسعاف'],
            ['اعصاب', 'عصاب', 'عصبي', 'صداع', 'دوخه', 'تنميل', 'راس', 'راسي', 'راسو', 'رأس', 'نصفية'],
            ['عظام', 'مفاصل', 'كسر', 'ركبه', 'ركبت', 'ظهر', 'ضهر', 'غضروف', 'ورك'],
            ['جلد', 'جلديه', 'حساسيه', 'طفح'],
            ['انف', 'اذن', 'حنجره', 'بحه'],
            ['مسالك', 'كليه', 'بول', 'حصوات'],
        ] as $aliases) {
            if ($this->containsAny($normalized, $aliases) && $this->containsAny($departmentText, $aliases)) {
                $score += 5;
            }
        }

        return $score;
    }

    private function slotMatchesPeriod(string $slot, string $period): bool
    {
        $hour = (int) substr($slot, 0, 2);
        return match ($period) {
            'morning' => $hour < 12,
            'afternoon' => $hour >= 12,
            default => true,
        };
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = $this->normalize((string) $needle);
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tokens(string $normalized): array
    {
        return array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));
    }

    private function isStopWord(string $token): bool
    {
        return in_array($token, [
            'انا', 'انت', 'هو', 'هي', 'من', 'في', 'على', 'عن', 'الى',
            'شو', 'ايش', 'بدي', 'اريد', 'ممكن', 'لو', 'سمحت', 'عند', 'مع',
            'هذا', 'هاي', 'اللي',
            // Palestinian dialect fillers that cause false doctor-name matches
            'بس', 'مش', 'مو', 'لاي', 'عارف', 'عارفه', 'بعرف', 'باعرف',
            'ادري', 'بدون', 'كمان', 'هيك', 'هيكا', 'هون',
        ], true);
    }

    private function isDoctorSelectionStopWord(string $token): bool
    {
        return $this->isStopWord($token) || in_array($token, ['د', 'دكتور', 'الدكتور', 'طبيب', 'الدكتوره', 'دكتوره', 'هاتلي', 'اختار', 'احجز', 'موعد'], true);
    }

    private function normalizeDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
    }
}
