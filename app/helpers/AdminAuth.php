<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';

class AdminAuth
{
    private const SESSION_KEY = 'admin_auth';

    public static function login(array $admin): void
    {
        $_SESSION[self::SESSION_KEY] = ['admin_id' => (int) $admin['admin_id']];
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public static function id(): ?int
    {
        $id = $_SESSION[self::SESSION_KEY]['admin_id'] ?? null;
        return is_numeric($id) ? (int) $id : null;
    }

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function admin(): ?array
    {
        $id = self::id();
        if ($id === null) return null;

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT admin_id, username, email, full_name, role FROM AdminUser WHERE admin_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function require(): array
    {
        $admin = self::admin();
        if ($admin === null) {
            Response::unauthorized('يجب تسجيل دخول الأدمن أولاً.');
        }
        return $admin;
    }

    public static function requireSuperAdmin(): array
    {
        $admin = self::require();
        if ($admin['role'] !== 'super_admin') {
            Response::forbidden('هذه العملية تتطلب صلاحيات المدير العام.');
        }
        return $admin;
    }
}
