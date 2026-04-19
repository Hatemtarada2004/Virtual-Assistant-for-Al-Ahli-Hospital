<?php

require_once __DIR__ . '/../services/DoctorService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';

/**
 * DoctorController
 * ----------------
 * GET /api/doctors
 * GET /api/doctors?department_id=1
 * GET /api/doctors?specialty=cardiology
 * GET /api/doctors/{id}
 */
class DoctorController
{
    private DoctorService $service;

    public function __construct()
    {
        $this->service = new DoctorService();
    }

    /**
     * GET /api/doctors
     * يدعم الفلترة عبر query params:
     *   ?department_id=1
     *   ?specialty=قلب
     */
    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $departmentId = Request::query('department_id');
            $specialty    = Request::query('specialty');

            if ($departmentId !== null && $departmentId !== '') {
                if (!filter_var($departmentId, FILTER_VALIDATE_INT)) {
                    Response::error('department_id يجب أن يكون عدداً صحيحاً.', null, 422);
                }
                $doctors = $this->service->getByDepartment((int) $departmentId);
                Response::success($doctors, 'تم جلب أطباء القسم بنجاح');
            }

            if ($specialty !== null && $specialty !== '') {
                $doctors = $this->service->getBySpecialty(trim($specialty));
                Response::success($doctors, 'تم جلب الأطباء حسب التخصص بنجاح');
            }

            // بدون فلترة — جلب الكل
            $doctors = $this->service->getAll();
            Response::success($doctors, 'تم جلب جميع الأطباء بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/doctors/{id}
     * جلب طبيب واحد بمعرّفه.
     *
     * @param int $id
     */
    public function show(int $id): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $doctor = $this->service->getById($id);

            if ($doctor === null) {
                Response::notFound("الطبيب بالمعرّف {$id} غير موجود.");
            }

            Response::success($doctor, 'تم جلب بيانات الطبيب بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
