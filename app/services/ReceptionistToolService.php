<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/DepartmentRepository.php';
require_once __DIR__ . '/../repositories/DoctorRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/ArabicPatientTextNormalizerService.php';
require_once __DIR__ . '/EmailOtpService.php';
require_once __DIR__ . '/RagRetrievalService.php';

class ReceptionistToolService
{
    private DepartmentRepository $departments;
    private DoctorRepository $doctors;
    private PatientRepository $patients;
    private AppointmentService $appointments;
    private EmailOtpService $emailOtp;
    private RagRetrievalService $rag;
    private ArabicPatientTextNormalizerService $normalizer;

    public function __construct()
    {
        $this->departments = new DepartmentRepository();
        $this->doctors = new DoctorRepository();
        $this->patients = new PatientRepository();
        $this->appointments = new AppointmentService();
        $this->emailOtp = new EmailOtpService();
        $this->rag = new RagRetrievalService();
        $this->normalizer = new ArabicPatientTextNormalizerService();
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

        if ($normalized === '') {
            return array_slice($doctors, 0, 6);
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

        return array_map(function (array $doctor): array {
            unset($doctor['_score']);
            return $doctor;
        }, array_slice($scored, 0, 6));
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

    public function createOrGetPatient(string $email, ?string $name = null): array
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $patient = $this->patients->findByEmail($email);
        if ($patient !== null) {
            return $patient->toArray();
        }

        $id = $this->patients->createFromChatEmail($email, $name);
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

        $patient = $this->createOrGetPatient(
            (string) ($booking['patient_email'] ?? ''),
            $booking['patient_name'] ?? null
        );

        return $this->appointments->book([
            'patient_id' => (int) $patient['patient_id'],
            'doctor_id' => (int) $doctor['doctor_id'],
            'department_id' => (int) $doctor['department_id'],
            'appointment_datetime' => (string) $booking['selected_date'] . ' ' . (string) $booking['selected_time'] . ':00',
            'reason' => (string) ($booking['reason'] ?? 'حجز عبر الشات بوت'),
        ]);
    }

    public function searchHospitalKnowledge(string $query): string
    {
        return $this->rag->contextForPrompt($query);
    }

    public function extractEmail(string $message): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $message, $m)) {
            return mb_strtolower($m[0], 'UTF-8');
        }

        return null;
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
        $normalized = $this->normalize($message);
        return $this->containsAny($normalized, [
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
        ]);
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
        if ($this->extractEmail($message) !== null || $this->extractVerificationCode($message) !== null) {
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
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s:-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, session_id() !== '' ? session_id() : __FILE__);
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
                if ($percent >= 78) {
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
        return in_array($token, ['انا', 'انت', 'هو', 'هي', 'من', 'في', 'على', 'عن', 'الى', 'شو', 'ايش', 'بدي', 'اريد', 'ممكن', 'لو', 'سمحت', 'عند', 'مع', 'هذا', 'هاي', 'اللي'], true);
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
