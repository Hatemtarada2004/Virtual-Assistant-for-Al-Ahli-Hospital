<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Feedback.php';

/**
 * FeedbackRepository
 * ------------------
 * استعلامات جدول Feedback.
 */
class FeedbackRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب feedback واحدة بمعرّفها.
     *
     * @param  int $id
     * @return Feedback|null
     */
    public function findById(int $id): ?Feedback
    {
        $stmt = $this->pdo->prepare('
            SELECT f.feedback_id,
                   f.patient_id,
                   f.type,
                   f.message,
                   f.status,
                   f.created_at,
                   p.full_name AS patient_name
            FROM   Feedback f
            LEFT   JOIN Patient p ON p.patient_id = f.patient_id
            WHERE  f.feedback_id = :id
            LIMIT  1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new Feedback($row) : null;
    }

    /**
     * جلب جميع الـ feedback مرتبة من الأحدث.
     *
     * @return Feedback[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT f.feedback_id,
                   f.patient_id,
                   f.type,
                   f.message,
                   f.status,
                   f.created_at,
                   p.full_name AS patient_name
            FROM   Feedback f
            LEFT   JOIN Patient p ON p.patient_id = f.patient_id
            ORDER  BY f.created_at DESC
        ');
        $stmt->execute();

        return array_map(fn(array $row) => new Feedback($row), $stmt->fetchAll());
    }

    // -------------------------------------------------------
    // WRITE
    // -------------------------------------------------------

    /**
     * حفظ feedback أو شكوى جديدة.
     * patient_id يمكن أن يكون null إذا كان المرسل زائراً.
     *
     * @param  int|null $patientId
     * @param  string   $type       Feedback | Complaint
     * @param  string   $message
     * @return int      ID السجل المُنشأ
     */
    public function create(?int $patientId, string $type, string $message): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO Feedback (patient_id, type, message, status)
            VALUES (:patient_id, :type, :message, :status)
        ');
        $stmt->execute([
            ':patient_id' => $patientId,
            ':type'       => $type,
            ':message'    => $message,
            ':status'     => Feedback::STATUS_NEW,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
