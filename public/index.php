<?php
declare(strict_types=1);

// -------------------------------------------------------
// 1. Output buffering أولاً — يمنع أي نص يسبق JSON
// -------------------------------------------------------
ob_start();

// إخفاء الأخطاء من الـ output — تُسجَّل في الـ log فقط
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

mb_internal_encoding('UTF-8');
define('APP_ROOT', dirname(__DIR__));

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = APP_ROOT . '/storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0775, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    session_name('AHLI_PATIENT_SESSION');
    session_start();
}

// -------------------------------------------------------
// 2. Global Exception Handler — يعيد JSON بدل HTML
// -------------------------------------------------------
set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'success' => false,
        'message' => 'خطأ في الخادم: ' . $e->getMessage(),
        'file'    => basename($e->getFile()) . ':' . $e->getLine(),
        'errors'  => null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
});

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // تحويل PHP errors إلى exceptions ليُعالجها exception handler
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

// -------------------------------------------------------
// 3. CORS
// -------------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// -------------------------------------------------------
// 4. استخراج المسار
// -------------------------------------------------------
$requestUri   = $_SERVER['REQUEST_URI'] ?? '/';
$uriWithoutQs = strtok($requestUri, '?');
$scriptDir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path         = '/' . ltrim(substr($uriWithoutQs, strlen($scriptDir)), '/');

if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

$method = strtoupper($_SERVER['REQUEST_METHOD']);

if (($_SERVER['HTTP_X_CHATBOT_TEST_DISABLE_LLM'] ?? '') === '1'
    && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    putenv('CHATBOT_TEST_DISABLE_LLM=1');
}

// -------------------------------------------------------
// 5. تحميل المسارات وتنفيذ الـ Controller
// -------------------------------------------------------
$routes = require APP_ROOT . '/routes/api.php';

foreach ($routes as [$routeMethod, $routePattern, $controllerClass, $action, $hasId]) {

    if ($routeMethod !== $method) {
        continue;
    }

    if ($hasId) {
        $regexPattern = preg_replace('/\{[^}]+\}/', '(\d+)', $routePattern);
        $regexPattern = '@^' . $regexPattern . '$@';

        if (preg_match($regexPattern, $path, $matches)) {
            $id = (int) $matches[1];
            // تنظيف أي output طارئ قبل إرسال الرد
            ob_clean();
            $controller = new $controllerClass();
            $controller->$action($id);
            exit;
        }
    } else {
        if ($routePattern === $path) {
            ob_clean();
            $controller = new $controllerClass();
            $controller->$action();
            exit;
        }
    }
}

// -------------------------------------------------------
// 6. 404
// -------------------------------------------------------
ob_clean();
http_response_code(404);
echo json_encode([
    'success' => false,
    'message' => "المسار [{$method} {$path}] غير موجود.",
    'errors'  => null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
