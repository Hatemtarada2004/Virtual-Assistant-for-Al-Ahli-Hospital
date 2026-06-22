<?php

declare(strict_types=1);

class RagRetrievalService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/load_env.php';
    }

    public function contextForPrompt(string $query): string
    {
        if (!($this->config['rag_enabled'] ?? false)) {
            return '';
        }

        $matches = $this->search($query);
        if (empty($matches)) {
            return '';
        }

        $lines = [];
        foreach (array_slice($matches, 0, (int) ($this->config['rag_top_k'] ?? 5)) as $i => $match) {
            $text = trim((string) ($match['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $source = trim((string) ($match['source'] ?? ''));
            $score = isset($match['score']) ? ' score=' . round((float) $match['score'], 4) : '';
            $lines[] = ($i + 1) . '. ' . $text . ($source !== '' ? " [{$source}{$score}]" : '');
        }

        if (empty($lines)) {
            return '';
        }

        return "سياق RAG مسترجع من قاعدة المعرفة:\n"
            . implode("\n", $lines)
            . "\nاستخدم هذا السياق فقط إذا كان مفيداً لفهم صياغة المستخدم أو نية الطلب. لا تخترع معلومات غير موجودة في نظام المستشفى.";
    }

    private function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $url = rtrim((string) ($this->config['rag_url'] ?? ''), '/') . '/search';
        if ($url === '/search') {
            return [];
        }

        $payload = json_encode([
            'query' => $query,
            'top_k' => (int) ($this->config['rag_top_k'] ?? 5),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT_MS => (int) round(((float) ($this->config['rag_timeout'] ?? 1.0)) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $matches = $decoded['matches'] ?? [];
        return is_array($matches) ? $matches : [];
    }
}
