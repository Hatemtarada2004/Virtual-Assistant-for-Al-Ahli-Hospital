<?php

require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/Response.php';

class SessionAuth
{
    private const SESSION_KEY = 'patient_auth';

    public static function login(array $patient): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'patient_id' => (int) $patient['patient_id'],
        ];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION['chat_state']);
    }

    public static function id(): ?int
    {
        $patientId = $_SESSION[self::SESSION_KEY]['patient_id'] ?? null;
        return is_numeric($patientId) ? (int) $patientId : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function patient(): ?array
    {
        $patientId = self::id();
        if ($patientId === null) {
            return null;
        }

        $patient = (new PatientRepository())->findById($patientId);
        return $patient?->toArray();
    }

    public static function requirePatient(): array
    {
        $patient = self::patient();
        if ($patient === null) {
            Response::unauthorized('يجب تأكيد هوية المريض أولاً للوصول إلى هذه الخدمة.');
        }

        return $patient;
    }

    public static function requireOwner(int $patientId): array
    {
        $patient = self::requirePatient();
        if ((int) $patient['patient_id'] !== $patientId) {
            Response::forbidden('غير مسموح لك بالوصول إلى بيانات مريض آخر.');
        }

        return $patient;
    }
}
