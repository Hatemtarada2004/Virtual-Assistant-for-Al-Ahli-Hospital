<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';

class LabAuth
{
    private const SESSION_KEY = 'lab_auth';

    public static function login(array $user): void
    {
        $_SESSION[self::SESSION_KEY] = ['lab_user_id' => (int) $user['lab_user_id']];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function id(): ?int
    {
        $id = $_SESSION[self::SESSION_KEY]['lab_user_id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) return null;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT lab_user_id, username, full_name FROM LabUser WHERE lab_user_id = ? AND is_active = 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function require(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::unauthorized('يجب تسجيل دخول المختبر أولاً.');
        }
        return $user;
    }
}
