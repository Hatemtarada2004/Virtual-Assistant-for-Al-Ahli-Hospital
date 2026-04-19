<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/InsuranceProvider.php';
require_once __DIR__ . '/../models/InsurancePolicy.php';

/**
 * InsuranceRepository
 * -------------------
 * استعلامات جدولي InsuranceProvider و InsurancePolicy.
 */
class InsuranceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // InsuranceProvider
    // -------------------------------------------------------

    /**
     * جلب جميع شركات التأمين.
     *
     * @return InsuranceProvider[]
     */
    public function findAllProviders(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT provider_id, name, phone
            FROM   InsuranceProvider
            ORDER  BY provider_id ASC
        ');
        $stmt->execute();

        return array_map(fn(array $row) => new InsuranceProvider($row), $stmt->fetchAll());
    }

    /**
     * جلب شركة تأمين واحدة بمعرّفها.
     *
     * @param  int $id
     * @return InsuranceProvider|null
     */
    public function findProviderById(int $id): ?InsuranceProvider
    {
        $stmt = $this->pdo->prepare('
            SELECT provider_id, name, phone
            FROM   InsuranceProvider
            WHERE  provider_id = :id
            LIMIT  1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new InsuranceProvider($row) : null;
    }

    // -------------------------------------------------------
    // InsurancePolicy
    // -------------------------------------------------------

    /**
     * جلب بوليصة واحدة بمعرّفها مع بيانات المريض والشركة.
     *
     * @param  int $id
     * @return InsurancePolicy|null
     */
    public function findPolicyById(int $id): ?InsurancePolicy
    {
        $stmt = $this->pdo->prepare('
            SELECT ip.policy_id,
                   ip.patient_id,
                   ip.provider_id,
                   ip.policy_number,
                   ip.coverage_details,
                   ip.valid_from,
                   ip.valid_to,
                   p.full_name  AS patient_name,
                   prov.name    AS provider_name,
                   prov.phone   AS provider_phone
            FROM   InsurancePolicy   ip
            JOIN   Patient           p    ON p.patient_id    = ip.patient_id
            JOIN   InsuranceProvider prov ON prov.provider_id = ip.provider_id
            WHERE  ip.policy_id = :id
            LIMIT  1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new InsurancePolicy($row) : null;
    }

    /**
     * جلب جميع بوالص تأمين مريض معين مع بيانات شركة التأمين.
     * مرتبة بتاريخ الانتهاء (الأحدث أولاً).
     *
     * @param  int $patientId
     * @return InsurancePolicy[]
     */
    public function findPoliciesByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ip.policy_id,
                   ip.patient_id,
                   ip.provider_id,
                   ip.policy_number,
                   ip.coverage_details,
                   ip.valid_from,
                   ip.valid_to,
                   p.full_name  AS patient_name,
                   prov.name    AS provider_name,
                   prov.phone   AS provider_phone
            FROM   InsurancePolicy   ip
            JOIN   Patient           p    ON p.patient_id    = ip.patient_id
            JOIN   InsuranceProvider prov ON prov.provider_id = ip.provider_id
            WHERE  ip.patient_id = :patient_id
            ORDER  BY ip.valid_to DESC
        ');
        $stmt->execute([':patient_id' => $patientId]);

        return array_map(fn(array $row) => new InsurancePolicy($row), $stmt->fetchAll());
    }

    /**
     * جلب بوالص التأمين السارية فقط (valid_to >= اليوم) لمريض معين.
     *
     * @param  int $patientId
     * @return InsurancePolicy[]
     */
    public function findActivePoliciesByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ip.policy_id,
                   ip.patient_id,
                   ip.provider_id,
                   ip.policy_number,
                   ip.coverage_details,
                   ip.valid_from,
                   ip.valid_to,
                   p.full_name  AS patient_name,
                   prov.name    AS provider_name,
                   prov.phone   AS provider_phone
            FROM   InsurancePolicy   ip
            JOIN   Patient           p    ON p.patient_id    = ip.patient_id
            JOIN   InsuranceProvider prov ON prov.provider_id = ip.provider_id
            WHERE  ip.patient_id = :patient_id
              AND  ip.valid_to  >= CURDATE()
            ORDER  BY ip.valid_to DESC
        ');
        $stmt->execute([':patient_id' => $patientId]);

        return array_map(fn(array $row) => new InsurancePolicy($row), $stmt->fetchAll());
    }
}
