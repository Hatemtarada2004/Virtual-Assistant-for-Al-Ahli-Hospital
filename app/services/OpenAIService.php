<?php

require_once __DIR__ . '/../config/env.php';

/**
 * OpenAIService
 * -------------
 * مسؤول عن إرسال الـ prompt إلى OpenAI Chat Completions API عبر cURL.
 * يُعيد نص الرد فقط — لا يتعامل مع قاعدة البيانات.
 */
class OpenAIService
{
    private string $apiKey;
    private string $apiUrl;
    private string $model;
    private int    $timeout;
    private array  $extraHeaders;

    private const DEFAULT_API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $config        = require __DIR__ . '/../config/env.php';
        $this->apiKey  = $config['openai_api_key'];
        $this->apiUrl  = (string) ($config['openai_api_url'] ?? self::DEFAULT_API_URL);
        $this->model   = $config['openai_model'];
        $this->timeout = (int) $config['openai_timeout'];
        $this->extraHeaders = is_array($config['openai_extra_headers'] ?? null)
            ? $config['openai_extra_headers']
            : [];
    }

    // -------------------------------------------------------
    // Public
    // -------------------------------------------------------

    /**
     * إرسال رسائل المحادثة إلى OpenAI وإرجاع نص الرد.
     *
     * @param  array  $messages  مصفوفة بصيغة [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
     * @param  int    $maxTokens الحد الأقصى لعدد tokens في الرد
     * @return string نص الرد من النموذج
     * @throws RuntimeException عند فشل الاتصال أو خطأ من API
     */
    public function chat(array $messages, int $maxTokens = 1024, float $temperature = 0.4): string
    {
        $this->validateApiKey();
        $temperature = max(0.0, min(1.0, $temperature));

        $payload = json_encode([
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        foreach ($this->extraHeaders as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $headers[] = $name . ': ' . $value;
            }
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            // تعطيل تحقق شهادة SSL في بيئة XAMPP المحلية
            // في الإنتاج: احذف السطرين التاليين
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // خطأ في الاتصال
        if ($rawResponse === false) {
            throw new RuntimeException(
                'OpenAI cURL connection failed: ' . $curlError
            );
        }

        $decoded = json_decode($rawResponse, true);

        // خطأ في تحليل JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'OpenAI returned invalid JSON. HTTP ' . $httpCode
            );
        }

        // خطأ من API نفسه (مفتاح منتهي، حد الاستخدام، إلخ)
        if (isset($decoded['error'])) {
            $errorMsg = $decoded['error']['message'] ?? 'Unknown OpenAI API error';
            throw new RuntimeException('OpenAI API error: ' . $errorMsg);
        }

        // استخرج نص الرد
        $content = $decoded['choices'][0]['message']['content'] ?? null;

        if ($content === null || trim($content) === '') {
            throw new RuntimeException('OpenAI returned an empty response.');
        }

        return trim($content);
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    /**
     * التأكد من أن مفتاح API تم تعيينه قبل الإرسال.
     */
    private function validateApiKey(): void
    {
        if (empty($this->apiKey) || $this->apiKey === 'PUT_YOUR_OPENAI_KEY_HERE') {
            throw new RuntimeException(
                'OpenAI API key is not configured. Please set openai_api_key in app/config/env.php'
            );
        }
    }
}
