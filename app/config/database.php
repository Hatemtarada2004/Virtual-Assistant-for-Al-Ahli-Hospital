<?php

/**
 * database.php
 * ------------
 * مسؤول عن إنشاء اتصال PDO واحد مع قاعدة البيانات (Singleton Pattern).
 * يُستدعى من أي Repository يحتاج إلى تنفيذ استعلامات SQL.
 */

class Database
{
    /** @var PDO|null النسخة الوحيدة من الاتصال */
    private static ?PDO $instance = null;

    /**
     * منع إنشاء كائنات مباشرة من الخارج.
     */
    private function __construct() {}

    /**
     * منع النسخ.
     */
    private function __clone() {}

    /**
     * إرجاع اتصال PDO واحد مشترك.
     * يُنشئ الاتصال في أول استدعاء فقط ثم يعيد نفس الكائن.
     *
     * @return PDO
     * @throws RuntimeException عند فشل الاتصال بقاعدة البيانات
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/env.php';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['db_host'],
                $config['db_port'],
                $config['db_name'],
                $config['db_charset']
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            try {
                self::$instance = new PDO($dsn, $config['db_user'], $config['db_password'], $options);
            } catch (PDOException $e) {
                // نرمي exception ليتولى global handler في index.php إرجاع JSON نظيف
                throw new RuntimeException(
                    'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(),
                    500,
                    $e
                );
            }
        }

        return self::$instance;
    }
}
