<?php

require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';
require_once __DIR__ . '/../services/AuthService.php';

class AuthController
{
    private AuthService $service;

    public function __construct()
    {
        $this->service = new AuthService();
    }

    public function register(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        try {
            $patient = $this->service->register(Request::body());
            SessionAuth::login($patient);
            Response::success($patient, 'تم إنشاء ملف المريض وتأكيد هويته بنجاح.', 201);
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

    public function login(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        try {
            $patient = $this->service->login(Request::body());
            SessionAuth::login($patient);
            Response::success($patient, 'تم التحقق من هوية المريض بنجاح.');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function me(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        $patient = SessionAuth::patient();
        Response::success($patient, $patient ? 'تم جلب بيانات الجلسة.' : 'لا يوجد مريض مؤكَّد الهوية حاليًا.');
    }

    public function logout(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        SessionAuth::logout();
        Response::success(null, 'تم إنهاء الجلسة بنجاح.');
    }
}
