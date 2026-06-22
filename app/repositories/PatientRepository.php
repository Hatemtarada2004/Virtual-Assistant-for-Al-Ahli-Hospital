<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Patient.php';

class PatientRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            ORDER BY patient_id ASC
        ');

        return array_map(fn(array $row) => new Patient($row), $stmt->fetchAll());
    }

    public function findById(int $id): ?Patient
    {
        $stmt = $this->pdo->prepare('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            WHERE patient_id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new Patient($row) : null;
    }

    public function findByNationalId(string $nationalId): ?Patient
    {
        $stmt = $this->pdo->prepare('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            WHERE national_id = :national_id
            LIMIT 1
        ');
        $stmt->execute([':national_id' => $nationalId]);

        $row = $stmt->fetch();
        return $row ? new Patient($row) : null;
    }

    public function findByPhone(string $phone): ?Patient
    {
        $stmt = $this->pdo->prepare('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            WHERE phone = :phone
            LIMIT 1
        ');
        $stmt->execute([':phone' => $phone]);

        $row = $stmt->fetch();
        return $row ? new Patient($row) : null;
    }

    public function findByEmail(string $email): ?Patient
    {
        $stmt = $this->pdo->prepare('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            WHERE email = :email
            ORDER BY patient_id ASC
            LIMIT 1
        ');
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();
        return $row ? new Patient($row) : null;
    }

    public function findByNationalIdAndPhone(string $nationalId, string $phone): ?Patient
    {
        $stmt = $this->pdo->prepare('
            SELECT patient_id, full_name, national_id, phone, email, date_of_birth, gender, created_at
            FROM Patient
            WHERE national_id = :national_id
              AND phone = :phone
            LIMIT 1
        ');
        $stmt->execute([
            ':national_id' => $nationalId,
            ':phone' => $phone,
        ]);

        $row = $stmt->fetch();
        return $row ? new Patient($row) : null;
    }

    public function existsById(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Patient WHERE patient_id = :id');
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function nationalIdExists(string $nationalId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Patient WHERE national_id = :national_id');
        $stmt->execute([':national_id' => $nationalId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function phoneExists(string $phone): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Patient WHERE phone = :phone');
        $stmt->execute([':phone' => $phone]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(
        string $fullName,
        string $nationalId,
        string $phone,
        ?string $email,
        ?string $dateOfBirth,
        ?string $gender
    ): int {
        $stmt = $this->pdo->prepare('
            INSERT INTO Patient (full_name, national_id, phone, email, date_of_birth, gender)
            VALUES (:full_name, :national_id, :phone, :email, :date_of_birth, :gender)
        ');
        $stmt->execute([
            ':full_name' => $fullName,
            ':national_id' => $nationalId,
            ':phone' => $phone,
            ':email' => $email,
            ':date_of_birth' => $dateOfBirth,
            ':gender' => $gender,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createFromChatEmail(string $email, ?string $fullName = null): int
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $name = trim((string) $fullName);
        if ($name === '') {
            $localPart = preg_replace('/[^a-zA-Z0-9._-]+/', ' ', strtok($email, '@') ?: '') ?? '';
            $localPart = trim(str_replace(['.', '_', '-'], ' ', $localPart));
            $name = $localPart !== '' ? 'مريض ' . $localPart : 'مريض عبر الشات';
        }

        $phone = 'chat-' . substr(hash('sha256', $email), 0, 20);

        $stmt = $this->pdo->prepare('
            INSERT INTO Patient (full_name, national_id, phone, email, date_of_birth, gender)
            VALUES (:full_name, NULL, :phone, :email, NULL, NULL)
        ');
        $stmt->execute([
            ':full_name' => $name,
            ':phone' => $phone,
            ':email' => $email,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function createFromChatBooking(string $email, ?string $fullName = null, ?string $nationalId = null, ?string $phone = null): int
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        $name = trim((string) $fullName);
        if ($name === '') {
            $name = 'مريض عبر الشات';
        }

        $nationalId = trim((string) $nationalId);
        $realPhone = $phone !== null ? trim($phone) : '';
        $phoneSeed = $email . '|' . $nationalId;
        if ($realPhone !== '' && $this->phoneExists($realPhone)) {
            $realPhone = '';
        }
        $phone = $realPhone !== '' ? $realPhone : 'chat-' . substr(hash('sha256', $phoneSeed), 0, 20);

        $stmt = $this->pdo->prepare('
            INSERT INTO Patient (full_name, national_id, phone, email, date_of_birth, gender)
            VALUES (:full_name, :national_id, :phone, :email, NULL, NULL)
        ');
        $stmt->execute([
            ':full_name' => $name,
            ':national_id' => $nationalId !== '' ? $nationalId : null,
            ':phone' => $phone,
            ':email' => $email !== '' ? $email : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
