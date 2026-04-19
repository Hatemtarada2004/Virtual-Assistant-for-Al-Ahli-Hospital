<?php

require_once __DIR__ . '/../repositories/LabTestRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../helpers/Validator.php';

/**
 * LabTestService
 * --------------
 * Business logic لعمليات نتائج الفحوصات المخبرية.
 */
class LabTestService
{
    private LabTestRepository $labTestRepo;
    private PatientRepository $patientRepo;

    public function __construct()
    {
        $this->labTestRepo = new LabTestRepository();
        $this->patientRepo = new PatientRepository();
    }

    /**
     * جلب جميع الفحوصات المخبرية لمريض معين.
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $tests = $this->labTestRepo->findByPatientId($patientId);
        return array_map(fn($t) => $t->toArray(), $tests);
    }

    /**
     * جلب الفحوصات الجاهزة (Ready) فقط لمريض معين.
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getReadyByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $tests = $this->labTestRepo->findByPatientIdAndStatus($patientId, 'Ready');
        return array_map(fn($t) => $t->toArray(), $tests);
    }

    /**
     * جلب الفحوصات قيد الانتظار (Pending) لمريض معين.
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getPendingByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $tests = $this->labTestRepo->findByPatientIdAndStatus($patientId, 'Pending');
        return array_map(fn($t) => $t->toArray(), $tests);
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    /**
     * التحقق من وجود المريض — يرمي استثناء إذا لم يوجد.
     */
    private function assertPatientExists(int $patientId): void
    {
        if (!$this->patientRepo->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }
    }
}
