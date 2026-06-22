<?php
/**
 * ping.php — صفحة تشخيص سريع
 * افتح: http://localhost/hospital-chatbot/public/ping.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$result = ['php' => PHP_VERSION, 'status' => 'ok', 'checks' => []];

// 1. قاعدة البيانات
try {
    require_once __DIR__ . '/../app/config/database.php';
    $pdo = Database::getInstance();
    $pdo->query('SELECT 1');
    $result['checks']['database'] = '✅ متصل';
} catch (Throwable $e) {
    $result['checks']['database'] = '❌ ' . $e->getMessage();
    $result['status'] = 'error';
}

// 2. عدد السجلات
if (isset($pdo)) {
    foreach (['Department', 'Doctor', 'Patient', 'Service'] as $tbl) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM {$tbl}")->fetchColumn();
            $result['checks'][$tbl] = (int) $count . ' سجل';
        } catch (Throwable) {
            $result['checks'][$tbl] = '❌ جدول غير موجود';
        }
    }
}

// 3. فحص مفتاح OpenRouter + اختبار حي
try {
    $env = require __DIR__ . '/../app/config/load_env.php';
    $apiKey = trim((string) ($env['openai_api_key'] ?? ''));
    $apiUrl = trim((string) ($env['openai_api_url'] ?? ''));
    $model  = trim((string) ($env['openai_model']  ?? ''));
    $placeholders = ['PUT_YOUR_OPENROUTER_KEY_HERE', 'PUT_YOUR_OPENAI_KEY_HERE', ''];

    if (in_array($apiKey, $placeholders, true)) {
        $result['checks']['openai_key']  = '❌ لم يُضبط — افتح app/config/env.php';
        $result['checks']['openai_live'] = '⏭️ تم التخطي';
    } else {
        $result['checks']['openai_key']   = '✅ مُضبط (' . substr($apiKey, 0, 12) . '...)';
        $result['checks']['openai_model'] = $model;

        // اختبار حي — نرسل رسالة قصيرة ونتحقق من الرد
        $payload = json_encode([
            'model'      => $model,
            'messages'   => [
                ['role' => 'user', 'content' => 'قل كلمة "مرحبا" فقط بالعربية']
            ],
            'max_tokens' => 20,
            'temperature' => 0.1,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: http://localhost/hospital-chatbot',
            'X-Title: Ahli Hospital Chatbot',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl ?: 'https://openrouter.ai/api/v1/chat/completions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            $result['checks']['openai_live'] = '❌ cURL error: ' . $curlErr;
            $result['status'] = 'error';
        } else {
            $decoded = json_decode($raw, true);
            if (isset($decoded['error'])) {
                $result['checks']['openai_live'] = '❌ API error: ' . ($decoded['error']['message'] ?? 'unknown');
                $result['checks']['openai_raw']  = $decoded['error'];
                $result['status'] = 'error';
            } elseif (!empty($decoded['choices'][0]['message']['content'])) {
                $reply = trim($decoded['choices'][0]['message']['content']);
                $result['checks']['openai_live']  = '✅ يعمل — الرد: "' . $reply . '"';
                $result['checks']['openai_model_used'] = $decoded['model'] ?? $model;
            } else {
                $result['checks']['openai_live'] = '⚠️ HTTP ' . $httpCode . ' — رد فارغ';
                $result['checks']['openai_raw']  = $decoded;
                $result['status'] = 'warning';
            }
        }
    }
} catch (Throwable $e) {
    $result['checks']['openai_key']  = '❌ خطأ: ' . $e->getMessage();
    $result['status'] = 'config_error';
}

// 4. رابط API
$result['api_url'] = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . str_replace('/ping.php', '/api/chat', $_SERVER['SCRIPT_NAME']);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
