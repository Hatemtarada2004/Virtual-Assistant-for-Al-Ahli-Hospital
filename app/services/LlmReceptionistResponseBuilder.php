<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenAIService.php';
require_once __DIR__ . '/ReceptionistPromptService.php';

class LlmReceptionistResponseBuilder
{
    private array $config;
    private ReceptionistPromptService $prompts;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/env.php';
        $this->prompts = new ReceptionistPromptService();
    }

    public function finalReply(string $userMessage, string $draftReply, array $context = [], bool $allowLlm = true): string
    {
        $draftReply = trim($draftReply);
        if ($draftReply === '') {
            $draftReply = 'تمام، احكيلي شو المطلوب بالضبط حتى أساعدك.';
        }

        if (!$allowLlm || !($this->config['llm_receptionist_enabled'] ?? true)) {
            return $draftReply;
        }

        try {
            $ai = new OpenAIService();
            $messages = $this->prompts->buildMessages($userMessage, $draftReply, $context);
            $reply = $ai->chat($messages, 220, (float) ($this->config['llm_receptionist_temperature'] ?? 0.65));
            $reply = trim($reply);
            if ($reply === '' || $this->looksUnsafeRewrite($reply)) {
                return $draftReply;
            }

            return $this->stripWrapping($reply);
        } catch (Throwable) {
            return $draftReply;
        }
    }

    private function looksUnsafeRewrite(string $reply): bool
    {
        $lower = mb_strtolower($reply, 'UTF-8');
        return str_contains($lower, '{')
            || str_contains($lower, 'intent')
            || str_contains($lower, 'diagnosis')
            || str_contains($lower, 'json');
    }

    private function stripWrapping(string $reply): string
    {
        $reply = trim($reply);
        $reply = preg_replace('/^["“”]+|["“”]+$/u', '', $reply) ?? $reply;
        return trim($reply);
    }
}
