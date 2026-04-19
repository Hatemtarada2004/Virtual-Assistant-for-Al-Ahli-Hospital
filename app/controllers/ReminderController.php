<?php

require_once __DIR__ . '/../services/ReminderService.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';

class ReminderController
{
    private ReminderService $service;

    public function __construct()
    {
        $this->service = new ReminderService();
    }

    public function indexByPatient(int $id): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        SessionAuth::requireOwner($id);

        try {
            $reminders = $this->service->getByPatient($id);
            Response::success($reminders, 'تم جلب التذكيرات');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
