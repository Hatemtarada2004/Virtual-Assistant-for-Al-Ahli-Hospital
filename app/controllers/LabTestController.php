<?php

require_once __DIR__ . '/../services/LabTestService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';

class LabTestController
{
    private LabTestService $service;

    public function __construct()
    {
        $this->service = new LabTestService();
    }

    public function indexByPatient(int $patientId): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        SessionAuth::requireOwner($patientId);
        $status = Request::query('status');

        try {
            if ($status === 'Ready') {
                Response::success($this->service->getReadyByPatient($patientId), 'تم جلب الفحوصات الجاهزة بنجاح');
            }

            if ($status === 'Pending') {
                Response::success($this->service->getPendingByPatient($patientId), 'تم جلب الفحوصات قيد الانتظار بنجاح');
            }

            Response::success($this->service->getByPatient($patientId), 'تم جلب جميع الفحوصات بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 404);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
