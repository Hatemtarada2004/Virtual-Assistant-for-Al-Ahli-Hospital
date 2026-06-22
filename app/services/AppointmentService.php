<?php

require_once __DIR__ . '/../repositories/AppointmentRepository.php';
require_once __DIR__ . '/../repositories/DoctorRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../repositories/ServiceRepository.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * AppointmentService
 * ------------------
 * Business logic للمواعيد:
 * - التحقق من صحة المدخلات
 * - التحقق من وجود الطبيب والمريض
 * - منع التعارض في الأوقات
 * - حساب المواعيد المتاحة (available slots)
 * - إنشاء موعد جديد
 * - إلغاء موعد
 */
class AppointmentService
{
    private AppointmentRepository $appointmentRepo;
    private DoctorRepository      $doctorRepo;
    private PatientRepository     $patientRepo;
    private ServiceRepository     $serviceRepo;

    // ساعات العمل الافتراضية للمواعيد
    private const WORK_START     = '09:00';
    private const WORK_END       = '15:00';
    private const SLOT_MINUTES   = 30;

    public function __construct()
    {
        $this->appointmentRepo = new AppointmentRepository();
        $this->doctorRepo      = new DoctorRepository();
        $this->patientRepo     = new PatientRepository();
        $this->serviceRepo     = new ServiceRepository();
    }

    // -------------------------------------------------------
    // Available Slots
    // -------------------------------------------------------

    /**
     * حساب الأوقات المتاحة لطبيب معين في تاريخ محدد.
     * يفترض ساعات عمل من 09:00 إلى 15:00 كل 30 دقيقة.
     * يستبعد الأوقات المحجوزة لهذا الطبيب في هذا اليوم.
     *
     * @param  int      $doctorId
     * @param  string   $date       YYYY-MM-DD
     * @param  int|null $excludeAppointmentId موعد يتم تجاهله عند إعادة الجدولة
     * @return array  ['available_slots' => [...], 'booked_slots' => [...], 'doctor_id' => int, 'date' => string]
     * @throws InvalidArgumentException
     */
    public function getAvailableSlots(int $doctorId, string $date, ?int $excludeAppointmentId = null): array
    {
        // التحقق من صحة التاريخ
        $errors = Validator::validate(
            ['date' => $date, 'doctor_id' => $doctorId],
            ['date' => ['required', 'date'], 'doctor_id' => ['required', 'integer']]
        );
        if (!empty($errors)) {
            throw new InvalidArgumentException(implode(' | ', array_merge(...array_values($errors))));
        }

        // التحقق من وجود الطبيب
        if (!$this->doctorRepo->existsById($doctorId)) {
            throw new InvalidArgumentException("الطبيب بالمعرّف {$doctorId} غير موجود.");
        }

        // التحقق من أن التاريخ ليس في الماضي
        if ($date < date('Y-m-d')) {
            throw new InvalidArgumentException("لا يمكن عرض المواعيد لتاريخ مضى.");
        }

        // توليد جميع الـ slots المحتملة
        $allSlots    = $this->generateSlots($date);

        // جلب الأوقات المحجوزة من قاعدة البيانات
        $bookedTimes = $this->appointmentRepo->findBookedTimesByDoctorAndDate($doctorId, $date, $excludeAppointmentId);

        // تحويل الأوقات المحجوزة إلى HH:MM لتسهيل المقارنة
        $bookedNormalized = array_map(
            fn(string $t) => substr($t, 0, 5),   // HH:MM:SS -> HH:MM
            $bookedTimes
        );

        // الأوقات المتاحة = الكل - المحجوز
        $availableSlots = array_values(
            array_filter($allSlots, fn(string $slot) => !in_array($slot, $bookedNormalized, true))
        );

        return [
            'doctor_id'       => $doctorId,
            'date'            => $date,
            'work_start'      => self::WORK_START,
            'work_end'        => self::WORK_END,
            'slot_minutes'    => self::SLOT_MINUTES,
            'available_slots' => $availableSlots,
            'booked_slots'    => $bookedNormalized,
            'total_available' => count($availableSlots),
        ];
    }

    // -------------------------------------------------------
    // Book Appointment
    // -------------------------------------------------------

    /**
     * إنشاء موعد جديد بعد جميع التحققات.
     *
     * @param  array $data  يجب أن يحتوي: patient_id, doctor_id, department_id, appointment_datetime, reason (اختياري)
     * @return array  الموعد المُنشأ
     * @throws InvalidArgumentException عند فشل أي تحقق
     */
    public function book(array $data): array
    {
        // --- التحقق من الحقول المطلوبة ---
        $errors = Validator::validate($data, [
            'patient_id'           => ['required', 'integer'],
            'doctor_id'            => ['required', 'integer'],
            'department_id'        => ['required', 'integer'],
            'appointment_datetime' => ['required', 'datetime'],
        ]);

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $patientId    = (int) $data['patient_id'];
        $doctorId     = (int) $data['doctor_id'];
        $departmentId = (int) $data['department_id'];
        $datetime     = (string) $data['appointment_datetime'];
        $reason       = isset($data['reason']) && $data['reason'] !== '' ? (string) $data['reason'] : null;
        $serviceIds   = [];

        if (isset($data['service_ids']) && is_array($data['service_ids'])) {
            $serviceIds = array_values(array_unique(array_map('intval', $data['service_ids'])));
        }

        // --- التحقق من وجود المريض ---
        if (!$this->patientRepo->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }

        // --- التحقق من وجود الطبيب ---
        if (!$this->doctorRepo->existsById($doctorId)) {
            throw new InvalidArgumentException("الطبيب بالمعرّف {$doctorId} غير موجود.");
        }

        // --- التحقق من أن التاريخ في المستقبل ---
        foreach ($serviceIds as $serviceId) {
            if ($serviceId <= 0 || !$this->serviceRepo->existsById($serviceId)) {
                throw new InvalidArgumentException("الخدمة بالمعرّف {$serviceId} غير موجودة.");
            }
        }

        $appointmentTs = strtotime($datetime);
        if ($appointmentTs === false || $appointmentTs <= time()) {
            throw new InvalidArgumentException("يجب أن يكون وقت الموعد في المستقبل.");
        }

        // --- التحقق من أن الوقت ضمن ساعات العمل ---
        $this->validateWithinWorkingHours($datetime);

        // --- التحقق من عدم وجود تعارض ---
        // تحويل الوقت إلى صيغة موحدة YYYY-MM-DD HH:MM:SS
        $normalizedDatetime = date('Y-m-d H:i:s', $appointmentTs);

        if ($this->appointmentRepo->hasConflict($doctorId, $normalizedDatetime)) {
            throw new InvalidArgumentException(
                "الطبيب لديه موعد محجوز بالفعل في هذا الوقت. يرجى اختيار وقت آخر."
            );
        }

        // --- إنشاء الموعد ---
        $appointmentId = $this->appointmentRepo->create(
            $patientId,
            $doctorId,
            $departmentId,
            $normalizedDatetime,
            $reason
        );

        // جلب الموعد المُنشأ مع بيانات JOIN
        if (!empty($serviceIds)) {
            $this->appointmentRepo->replaceServices($appointmentId, $serviceIds);
        }

        $appointment = $this->appointmentRepo->findById($appointmentId);

        if ($appointment === null) {
            throw new RuntimeException("فشل استرجاع الموعد بعد الإنشاء.");
        }

        return $appointment->toArray();
    }

    // -------------------------------------------------------
    // Get By Patient
    // -------------------------------------------------------

    /**
     * جلب جميع مواعيد مريض معين.
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getByPatient(int $patientId): array
    {
        if (!$this->patientRepo->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }

        $appointments = $this->appointmentRepo->findByPatientId($patientId);
        return array_map(fn($a) => $a->toArray(), $appointments);
    }

    // -------------------------------------------------------
    // Cancel Appointment
    // -------------------------------------------------------

    /**
     * إلغاء موعد بتحويل حالته إلى Cancelled.
     *
     * @param  int $appointmentId
     * @return array  الموعد بعد الإلغاء
     * @throws InvalidArgumentException
     */
    public function cancel(int $appointmentId, ?int $patientId = null): array
    {
        // جلب الموعد أولاً
        $appointment = $this->appointmentRepo->findById($appointmentId);

        if ($appointment === null) {
            throw new InvalidArgumentException("الموعد بالمعرّف {$appointmentId} غير موجود.");
        }

        if ($patientId !== null && (int) $appointment->patient_id !== $patientId) {
            throw new InvalidArgumentException("لا يمكنك إلغاء موعد لا يخص ملفك.");
        }

        // التحقق من أن الموعد قابل للإلغاء
        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            throw new InvalidArgumentException("الموعد ملغى مسبقاً.");
        }

        if ($appointment->status === Appointment::STATUS_COMPLETED) {
            throw new InvalidArgumentException("لا يمكن إلغاء موعد مكتمل.");
        }

        if ($appointment->status === Appointment::STATUS_NO_SHOW) {
            throw new InvalidArgumentException("لا يمكن إلغاء موعد مسجّل كـ NoShow.");
        }

        // تنفيذ الإلغاء
        $affected = $this->appointmentRepo->updateStatus($appointmentId, Appointment::STATUS_CANCELLED);

        if ($affected === 0) {
            throw new RuntimeException("فشل تحديث حالة الموعد.");
        }

        // إرجاع الموعد المحدّث
        return $this->appointmentRepo->findById($appointmentId)->toArray();
    }

    // -------------------------------------------------------
    // Reschedule Appointment
    // -------------------------------------------------------

    /**
     * تعديل وقت موعد موجود مع التحقق من ملكية المريض وعدم وجود تعارض.
     *
     * @param  int    $appointmentId
     * @param  int    $patientId
     * @param  string $newDatetime YYYY-MM-DD HH:MM:SS
     * @return array
     */
    public function reschedule(int $appointmentId, int $patientId, string $newDatetime): array
    {
        $appointment = $this->appointmentRepo->findById($appointmentId);

        if ($appointment === null) {
            throw new InvalidArgumentException("الموعد بالمعرّف {$appointmentId} غير موجود.");
        }

        if ((int) $appointment->patient_id !== $patientId) {
            throw new InvalidArgumentException("لا يمكنك تعديل موعد لا يخص ملفك.");
        }

        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new InvalidArgumentException("يمكن تعديل المواعيد المحجوزة فقط.");
        }

        $appointmentTs = strtotime($newDatetime);
        if ($appointmentTs === false || $appointmentTs <= time()) {
            throw new InvalidArgumentException("يجب أن يكون وقت الموعد الجديد في المستقبل.");
        }

        $normalizedDatetime = date('Y-m-d H:i:s', $appointmentTs);
        $this->validateWithinWorkingHours($normalizedDatetime);

        if ($this->appointmentRepo->hasConflict((int) $appointment->doctor_id, $normalizedDatetime, $appointmentId)) {
            throw new InvalidArgumentException("هذا الوقت محجوز للطبيب نفسه. اختر وقتاً آخر.");
        }

        $affected = $this->appointmentRepo->updateDatetime($appointmentId, $normalizedDatetime);
        if ($affected === 0) {
            throw new RuntimeException("فشل تحديث وقت الموعد.");
        }

        $updated = $this->appointmentRepo->findById($appointmentId);
        if ($updated === null) {
            throw new RuntimeException("تم التحديث لكن تعذر استرجاع الموعد.");
        }

        return $updated->toArray();
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    /**
     * توليد قائمة بجميع الـ slots الممكنة في يوم عمل كامل.
     * من 09:00 إلى 15:00 كل 30 دقيقة.
     *
     * @param  string $date   YYYY-MM-DD
     * @return string[]       ['09:00', '09:30', '10:00', ...]
     */
    private function generateSlots(string $date): array
    {
        $slots  = [];
        $start  = strtotime($date . ' ' . self::WORK_START);
        $end    = strtotime($date . ' ' . self::WORK_END);
        $step   = self::SLOT_MINUTES * 60;

        for ($ts = $start; $ts <= $end; $ts += $step) {
            $slots[] = date('H:i', $ts);
        }

        return $slots;
    }

    /**
     * التحقق من أن وقت الموعد يقع ضمن ساعات العمل.
     *
     * @param  string $datetime   YYYY-MM-DD HH:MM:SS
     * @throws InvalidArgumentException
     */
    private function validateWithinWorkingHours(string $datetime): void
    {
        // استخرج جزء الوقت فقط
        $timeOnly = date('H:i', strtotime($datetime));

        if ($timeOnly < self::WORK_START || $timeOnly > self::WORK_END) {
            throw new InvalidArgumentException(
                "وقت الموعد يجب أن يكون بين " . self::WORK_START . " و " . self::WORK_END . "."
            );
        }

        // التحقق من أن الوقت على الـ 30 دقيقة (09:00, 09:30, 10:00, ...)
        $minutes = (int) date('i', strtotime($datetime));
        if ($minutes !== 0 && $minutes !== 30) {
            throw new InvalidArgumentException(
                "وقت الموعد يجب أن يكون على نصف الساعة أو بداية الساعة (مثال: 09:00 أو 09:30)."
            );
        }
    }
}
