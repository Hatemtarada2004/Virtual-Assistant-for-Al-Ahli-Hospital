<?php

require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/Response.php';

class PatientController
{
    private PatientRepository $patients;

    public function __construct()
    {
        $this->patients = new PatientRepository();
    }

    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        $patients = array_map(
            fn($patient) => $patient->toArray(),
            $this->patients->findAll()
        );

        Response::success($patients, 'تم جلب قائمة المرضى');
    }

    public function show(int $id): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        $patient = $this->patients->findById($id);
        if ($patient === null) {
            Response::notFound("المريض بالمعرّف {$id} غير موجود.");
        }

        Response::success($patient->toArray());
    }
}
