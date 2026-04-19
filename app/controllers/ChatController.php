<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/LlmReceptionistOrchestratorService.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/Response.php';

class ChatController
{
    private LlmReceptionistOrchestratorService $agent;

    public function __construct()
    {
        $this->agent = new LlmReceptionistOrchestratorService();
    }

    public function handle(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        $body = Request::body();
        $this->agent->syncClientSession(isset($body['chat_page_id']) ? (string) $body['chat_page_id'] : null);
        $message = trim((string) ($body['message'] ?? ''));

        if ($message === '') {
            Response::error('اكتب رسالة حتى أقدر أساعدك.', null, 422);
        }

        try {
            $result = $this->agent->reply($message);
            Response::chat(
                (string) ($result['intent'] ?? 'chat'),
                $result['data'] ?? [],
                (string) ($result['reply'] ?? 'أنا معك، كيف بقدر أساعدك؟'),
                'تم إنشاء رد الشات بنجاح'
            );
        } catch (Throwable $e) {
            Response::chat(
                'chat_error',
                ['error' => $e->getMessage()],
                'صار خطأ أثناء معالجة طلبك. جرّب مرة ثانية أو احكيلي طلبك بطريقة أبسط.',
                'تعذر إنشاء رد الشات'
            );
        }
    }
}
