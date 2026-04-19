<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/LabTest.php';

/**
 * LabTestRepository
 * -----------------
 * استعلامات جدول LabTest.
 */
class LabTestRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // Base SELECT
    // -------------------------------------------------------

    private function baseSelect(): string
    {
        return '
            SELECT lt.lab_test_id,
                   lt.patient_id,
                   lt.ordered_by_doctor_id,
                   lt.test_name,
                   lt.test_date,
                   lt.result_text,
                   lt.status,
                   p.full_name  AS patient_name,
                   d.full_name  AS doctor_name
            FROM   LabTest lt
            JOIN   Patient  p ON p.patient_id   = lt.patient_id
            LEFT   JOIN Doctor d ON d.doctor_id = lt.ordered_by_doctor_id
        ';
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب فحص واحد بمعرّفه.
     *
     * @param  int $id
     * @return LabTest|null
     */
    public function findById(int $id): ?LabTest
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE lt.lab_test_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new LabTest($row) : null;
    }

    /**
     * جلب جميع الفحوصات لمريض معين مرتبة من الأحدث.
     *
     * @param  int $patientId
     * @return LabTest[]
     */
    public function findByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE  lt.patient_id = :patient_id
            ORDER  BY lt.test_date DESC
        ');
        $stmt->execute([':patient_id' => $patientId]);

        return array_map(fn(array $row) => new LabTest($row), $stmt->fetchAll());
    }

    /**
     * جلب فحوصات مريض معين بحالة معينة فقط.
     * مثال: جلب الجاهزة فقط (Ready).
     *
     * @param  int    $patientId
     * @param  string $status     Pending | Ready
     * @return LabTest[]
     */
    public function findByPatientIdAndStatus(int $patientId, string $status): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE  lt.patient_id = :patient_id
              AND  lt.status     = :status
            ORDER  BY lt.test_date DESC
        ');
        $stmt->execute([
            ':patient_id' => $patientId,
            ':status'     => $status,
        ]);

        return array_map(fn(array $row) => new LabTest($row), $stmt->fetchAll());
    }
}
