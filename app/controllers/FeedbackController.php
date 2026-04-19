<?php

require_once __DIR__ . '/../services/FeedbackService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';

/**
 * FeedbackController
 * ------------------
 * POST /api/feedback
 */
class FeedbackController
{
    private FeedbackService $service;

    public function __construct()
    {
        $this->service = new FeedbackService();
    }

    /**
     * POST /api/feedback
     * تقديم ملاحظة أو شكوى.
     *
     * Body JSON:
     * {
     *   "type": "Feedback",
     *   "message": "الخدمة كانت ممتازة...",
     *   "patient_id": 1       // اختياري
     * }
     */
    public function store(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        $body = Request::body();

        if (empty($body)) {
            Response::error('لم يتم إرسال بيانات. تأكد من إرسال JSON في body الطلب.', null, 400);
        }

        try {
            $feedback = $this->service->submit($body);
            Response::success($feedback, 'تم تقديم ملاحظتك بنجاح. شكراً لك.', 201);
        } catch (InvalidArgumentException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Response::error('فشل التحقق من البيانات', $decoded, 422);
            }
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
