<?php

require_once __DIR__ . '/../repositories/FeedbackRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../models/Feedback.php';

/**
 * FeedbackService
 * ---------------
 * Business logic لحفظ الملاحظات والشكاوى.
 * patient_id اختياري — يسمح بالإرسال من زوار مجهولين.
 */
class FeedbackService
{
    private FeedbackRepository $feedbackRepo;
    private PatientRepository  $patientRepo;

    public function __construct()
    {
        $this->feedbackRepo = new FeedbackRepository();
        $this->patientRepo  = new PatientRepository();
    }

    /**
     * تقديم ملاحظة أو شكوى جديدة.
     *
     * @param  array $data  يجب أن يحتوي: type, message — patient_id اختياري
     * @return array  السجل المُنشأ
     * @throws InvalidArgumentException عند فشل التحقق
     */
    public function submit(array $data): array
    {
        // --- التحقق من الحقول المطلوبة ---
        $allowedTypes = implode(',', Feedback::allowedTypes());

        $errors = Validator::validate($data, [
            'type'    => ['required', "enum:{$allowedTypes}"],
            'message' => ['required', 'string', 'minLength:10'],
        ]);

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $type      = (string) $data['type'];
        $message   = trim((string) $data['message']);
        $patientId = null;

        // --- إذا أُرسل patient_id تحقق من وجوده ---
        if (isset($data['patient_id']) && $data['patient_id'] !== '' && $data['patient_id'] !== null) {
            $patientIdErrors = Validator::validate(
                ['patient_id' => $data['patient_id']],
                ['patient_id' => ['integer']]
            );

            if (!empty($patientIdErrors)) {
                throw new InvalidArgumentException("patient_id يجب أن يكون عدداً صحيحاً.");
            }

            $patientId = (int) $data['patient_id'];

            if (!$this->patientRepo->existsById($patientId)) {
                throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
            }
        }

        // --- الحفظ ---
        $feedbackId = $this->feedbackRepo->create($patientId, $type, $message);

        $feedback = $this->feedbackRepo->findById($feedbackId);

        if ($feedback === null) {
            throw new RuntimeException("فشل استرجاع الملاحظة بعد الحفظ.");
        }

        return $feedback->toArray();
    }
}
