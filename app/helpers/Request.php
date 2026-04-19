<?php

/**
 * Request.php
 * -----------
 * مساعد لقراءة بيانات الطلب الوارد (HTTP Request).
 * يوفر وصولاً موحداً لـ:
 *   - JSON body  (POST / PUT)
 *   - Query string parameters  (GET)
 *   - URL segments
 *   - HTTP method
 *   - Headers
 *
 * طريقة الاستخدام:
 *
 *   $method     = Request::method();           // 'GET'
 *   $path       = Request::path();             // '/api/departments/5'
 *   $segments   = Request::segments();         // ['api','departments','5']
 *   $body       = Request::body();             // مصفوفة من JSON body
 *   $field      = Request::input('name');      // حقل واحد من JSON body
 *   $query      = Request::query('date');      // حقل من query string
 *   $allQuery   = Request::queryAll();         // كامل query string
 */

class Request
{
    // -------------------------------------------------------
    // HTTP Method
    // -------------------------------------------------------

    /**
     * إرجاع طريقة HTTP للطلب بأحرف كبيرة.
     * مثال: GET, POST, PUT, DELETE
     */
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    // -------------------------------------------------------
    // URL / Path
    // -------------------------------------------------------

    /**
     * إرجاع مسار الطلب بدون query string.
     * مثال: /api/departments/5
     */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // أزل الـ query string
        $path = parse_url($uri, PHP_URL_PATH);

        // نظّف ال forward slashes الزائدة
        return '/' . trim($path, '/');
    }

    /**
     * إرجاع أجزاء المسار كمصفوفة.
     * مثال: /api/departments/5  ->  ['api', 'departments', '5']
     */
    public static function segments(): array
    {
        $path = self::path();
        return array_values(array_filter(explode('/', $path)));
    }

    /**
     * إرجاع جزء محدد من المسار (يبدأ العد من 0).
     * مثال: segment(2) من /api/departments/5  ->  '5'
     *
     * @param int $index
     * @return string|null
     */
    public static function segment(int $index): ?string
    {
        $segments = self::segments();
        return $segments[$index] ?? null;
    }

    // -------------------------------------------------------
    // JSON Body (POST / PUT)
    // -------------------------------------------------------

    /**
     * قراءة كامل JSON body وإرجاعه كمصفوفة.
     * يُستخدم في طلبات POST و PUT.
     *
     * @return array
     */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');

        if (empty($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * قراءة حقل واحد من JSON body.
     * إذا لم يوجد الحقل يُعاد null أو القيمة الافتراضية.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function input(string $key, mixed $default = null): mixed
    {
        $body = self::body();
        return $body[$key] ?? $default;
    }

    // -------------------------------------------------------
    // Query String (GET parameters)
    // -------------------------------------------------------

    /**
     * قراءة حقل واحد من query string.
     * مثال: ?department_id=2  ->  query('department_id') = '2'
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * إرجاع كامل query string كمصفوفة.
     *
     * @return array
     */
    public static function queryAll(): array
    {
        return $_GET ?? [];
    }

    // -------------------------------------------------------
    // Headers
    // -------------------------------------------------------

    /**
     * قراءة هيدر معين من الطلب.
     * PHP تحوّل أسماء الهيدرز إلى HTTP_NAME بحروف كبيرة.
     *
     * @param string $name   اسم الهيدر (مثال: Authorization)
     * @param mixed  $default
     * @return mixed
     */
    public static function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? $default;
    }

    /**
     * قراءة قيمة Authorization header.
     * يُستخدم لاحقاً لو أضفت JWT authentication.
     *
     * @return string|null
     */
    public static function bearerToken(): ?string
    {
        $auth = self::header('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    // -------------------------------------------------------
    // Content Type
    // -------------------------------------------------------

    /**
     * التحقق من أن Content-Type للطلب هو application/json.
     *
     * @return bool
     */
    public static function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * التحقق من طريقة الطلب.
     *
     * @param string $method  GET | POST | PUT | DELETE
     * @return bool
     */
    public static function isMethod(string $method): bool
    {
        return self::method() === strtoupper($method);
    }
}
