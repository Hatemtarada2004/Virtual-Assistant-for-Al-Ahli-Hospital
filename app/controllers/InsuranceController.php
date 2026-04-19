<?php

require_once __DIR__ . '/../services/InsuranceService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';

class InsuranceController
{
    private InsuranceService $service;

    public function __construct()
    {
        $this->service = new InsuranceService();
    }

    public function indexByPatient(int $patientId): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        SessionAuth::requireOwner($patientId);
        $activeOnly = Request::query('active');

        try {
            if ($activeOnly === '1' || $activeOnly === 'true') {
                Response::success($this->service->getActivePoliciesByPatient($patientId), 'تم جلب بوالص التأمين السارية بنجاح');
            }

            Response::success($this->service->getPoliciesByPatient($patientId), 'تم جلب بوالص التأمين بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 404);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
