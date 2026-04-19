<?php

require_once __DIR__ . '/../services/ServiceCatalogService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';

/**
 * ServiceController
 * -----------------
 * GET /api/services
 * GET /api/services?department_id=1
 * GET /api/services/{id}
 */
class ServiceController
{
    private ServiceCatalogService $service;

    public function __construct()
    {
        $this->service = new ServiceCatalogService();
    }

    /**
     * GET /api/services
     * يدعم الفلترة: ?department_id=1
     */
    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $departmentId = Request::query('department_id');

            if ($departmentId !== null && $departmentId !== '') {
                if (!filter_var($departmentId, FILTER_VALIDATE_INT)) {
                    Response::error('department_id يجب أن يكون عدداً صحيحاً.', null, 422);
                }
                $services = $this->service->getByDepartment((int) $departmentId);
                Response::success($services, 'تم جلب خدمات القسم بنجاح');
            }

            $services = $this->service->getAll();
            Response::success($services, 'تم جلب جميع الخدمات بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    /**
     * GET /api/services/{id}
     * جلب خدمة واحدة بمعرّفها.
     *
     * @param int $id
     */
    public function show(int $id): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        try {
            $serviceItem = $this->service->getById($id);

            if ($serviceItem === null) {
                Response::notFound("الخدمة بالمعرّف {$id} غير موجودة.");
            }

            Response::success($serviceItem, 'تم جلب بيانات الخدمة بنجاح');
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
