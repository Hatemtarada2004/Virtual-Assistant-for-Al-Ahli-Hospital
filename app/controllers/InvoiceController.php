<?php

require_once __DIR__ . '/../services/InvoiceService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';

class InvoiceController
{
    private InvoiceService $service;

    public function __construct()
    {
        $this->service = new InvoiceService();
    }

    public function indexByPatient(int $patientId): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        SessionAuth::requireOwner($patientId);

        try {
            Response::success($this->service->getByPatient($patientId), 'تم جلب الفواتير بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 404);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
