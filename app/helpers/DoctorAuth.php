<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';

class DoctorAuth
{
    private const SESSION_KEY = 'doctor_auth';

    public static function login(array $doctor): void
    {
        $_SESSION[self::SESSION_KEY] = ['doctor_id' => (int) $doctor['doctor_id']];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function id(): ?int
    {
        $id = $_SESSION[self::SESSION_KEY]['doctor_id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function doctor(): ?array
    {
        $id = self::id();
        if ($id === null) return null;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT doctor_id, full_name, specialty, email, phone, department_id FROM Doctor WHERE doctor_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function require(): array
    {
        $doctor = self::doctor();
        if ($doctor === null) {
            Response::unauthorized('يجب تسجيل دخول الطبيب أولاً.');
        }
        return $doctor;
    }
}
