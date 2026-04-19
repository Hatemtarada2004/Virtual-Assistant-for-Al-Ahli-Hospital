<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Reminder.php';

class ReminderRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    private function baseSelect(): string
    {
        return '
            SELECT r.reminder_id,
                   r.patient_id,
                   r.reminder_type,
                   r.message,
                   r.remind_at,
                   r.sent_flag,
                   p.full_name AS patient_name
            FROM Reminder r
            JOIN Patient p ON p.patient_id = r.patient_id
        ';
    }

    public function findByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE r.patient_id = :patient_id
            ORDER BY r.remind_at ASC
        '
        );
        $stmt->execute([':patient_id' => $patientId]);

        return array_map(fn(array $row) => new Reminder($row), $stmt->fetchAll());
    }

    public function findUpcomingByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE r.patient_id = :patient_id
              AND r.remind_at >= NOW()
            ORDER BY r.remind_at ASC
        '
        );
        $stmt->execute([':patient_id' => $patientId]);

        return array_map(fn(array $row) => new Reminder($row), $stmt->fetchAll());
    }
}
