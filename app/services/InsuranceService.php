<?php

require_once __DIR__ . '/../repositories/InsuranceRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../helpers/Validator.php';

/**
 * InsuranceService
 * ----------------
 * Business logic لعمليات التأمين الصحي.
 */
class InsuranceService
{
    private InsuranceRepository $insuranceRepo;
    private PatientRepository   $patientRepo;

    public function __construct()
    {
        $this->insuranceRepo = new InsuranceRepository();
        $this->patientRepo   = new PatientRepository();
    }

    /**
     * جلب جميع بوالص تأمين مريض معين (سارية ومنتهية).
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getPoliciesByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $policies = $this->insuranceRepo->findPoliciesByPatientId($patientId);
        return array_map(fn($p) => $p->toArray(), $policies);
    }

    /**
     * جلب بوالص التأمين السارية فقط لمريض معين (valid_to >= اليوم).
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getActivePoliciesByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $policies = $this->insuranceRepo->findActivePoliciesByPatientId($patientId);
        return array_map(fn($p) => $p->toArray(), $policies);
    }

    /**
     * جلب جميع شركات التأمين المتعاقد معها.
     *
     * @return array[]
     */
    public function getAllProviders(): array
    {
        $providers = $this->insuranceRepo->findAllProviders();
        return array_map(fn($p) => $p->toArray(), $providers);
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    private function assertPatientExists(int $patientId): void
    {
        if (!$this->patientRepo->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }
    }
}
