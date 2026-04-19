<?php
/**
 * ping.php — صفحة تشخيص سريع
 * افتح: http://localhost/Hospital/public/ping.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$result = ['php' => PHP_VERSION, 'status' => 'ok', 'checks' => []];

// 1. فحص قاعدة البيانات
try {
    require_once __DIR__ . '/../app/config/database.php';
    $pdo = Database::getInstance();
    $pdo->query('SELECT 1');
    $result['checks']['database'] = '✅ متصل';
} catch (Throwable $e) {
    $result['checks']['database'] = '❌ ' . $e->getMessage();
    $result['status'] = 'error';
}

// 2. فحص عدد السجلات
if ($result['status'] === 'ok') {
    foreach (['Department','Doctor','Patient','Service'] as $tbl) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            $result['checks'][$tbl] = (int)$count . ' سجل';
        } catch (Throwable $e) {
            $result['checks'][$tbl] = '❌ الجدول غير موجود';
            $result['status'] = 'db_error';
        }
    }
}

// 3. فحص مفتاح OpenAI
$env = require __DIR__ . '/../app/config/env.php';
$result['checks']['openai_key'] = ($env['openai_api_key'] === 'PUT_YOUR_OPENAI_KEY_HERE')
    ? '❌ لم يُضبط بعد — افتح app/config/env.php'
    : '✅ مُضبط';

// 4. فحص مسار API
$result['api_url'] = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . str_replace('/ping.php', '/api/chat', $_SERVER['SCRIPT_NAME']);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
