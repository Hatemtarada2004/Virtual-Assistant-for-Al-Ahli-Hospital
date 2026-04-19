<?php

require_once __DIR__ . '/../services/DepartmentService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';

/**
 * DepartmentController
 * --------------------
 * GET /api/departments
 * GET /api/departments/{id}
 */
class DepartmentController
{
    private DepartmentService $service;

    public function __construct()
    {
        $this->service = new DepartmentService();
    }

    /**
     * GET /api/departments
     * جلب جميع الأقسام.
     */
    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $departments = $this->service->getAll();
            Response::success($departments, 'تم جلب الأقسام بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/departments/{id}
     * جلب قسم واحد بمعرّفه.
     *
     * @param int $id
     */
    public function show(int $id): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $department = $this->service->getById($id);

            if ($department === null) {
                Response::notFound("القسم بالمعرّف {$id} غير موجود.");
            }

            Response::success($department, 'تم جلب بيانات القسم بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
