<?php

/**
 * Response.php
 * ------------
 * مساعد موحّد لإرسال ردود JSON منتظمة من جميع الـ Controllers.
 * كل رد يتبع نفس البنية سواء كان نجاحاً أو خطأ.
 *
 * بنية النجاح:
 * {
 *   "success": true,
 *   "message": "...",
 *   "data": ...
 * }
 *
 * بنية الخطأ:
 * {
 *   "success": false,
 *   "message": "...",
 *   "errors": ...
 * }
 */

class Response
{
    /**
     * إرسال رد ناجح.
     *
     * @param mixed       $data       البيانات المُعادة (مصفوفة، كائن، null)
     * @param string      $message    رسالة وصفية للنجاح
     * @param int         $statusCode كود HTTP (افتراضي 200)
     * @return void
     */
    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * إرسال رد بالخطأ.
     *
     * @param string      $message    رسالة الخطأ الرئيسية
     * @param mixed       $errors     تفاصيل الأخطاء (مصفوفة أخطاء validation مثلاً)
     * @param int         $statusCode كود HTTP (افتراضي 400)
     * @return void
     */
    public static function error(string $message = 'Error', mixed $errors = null, int $statusCode = 400): void
    {
        self::send([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $statusCode);
    }

    /**
     * إرسال رد 404 Not Found.
     *
     * @param string $message
     * @return void
     */
    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, null, 404);
    }

    /**
     * إرسال رد 405 Method Not Allowed.
     *
     * @return void
     */
    public static function methodNotAllowed(): void
    {
        self::error('Method not allowed', null, 405);
    }

    /**
     * إرسال رد 500 Internal Server Error.
     *
     * @param string $message
     * @return void
     */
    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, null, 500);
    }

    public static function unauthorized(string $message = 'Unauthorized', mixed $errors = null): void
    {
        self::error($message, $errors, 401);
    }

    public static function forbidden(string $message = 'Forbidden', mixed $errors = null): void
    {
        self::error($message, $errors, 403);
    }

    /**
     * إرسال رد خاص بالشات بوت يتضمن intent و data و reply.
     *
     * @param string $intent   النية المكتشفة
     * @param mixed  $data     البيانات المجلوبة من قاعدة البيانات
     * @param string $reply    الرد المولّد من OpenAI
     * @param string $message  رسالة وصفية
     * @return void
     */
    public static function chat(string $intent, mixed $data, string $reply, string $message = 'Chat reply generated successfully'): void
    {
        self::send([
            'success' => true,
            'intent'  => $intent,
            'message' => $message,
            'data'    => $data,
            'reply'   => $reply,
        ], 200);
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    /**
     * ضبط الهيدرز وإرسال JSON فعلياً.
     *
     * @param array $body
     * @param int   $statusCode
     * @return void
     */
    private static function send(array $body, int $statusCode): void
    {
        // امنع إرسال هيدرز مكررة إذا كانت قد أُرسلت مسبقاً
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
