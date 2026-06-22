<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/SessionAuth.php';

class AppointmentController
{
    private AppointmentService $service;

    public function __construct()
    {
        $this->service = new AppointmentService();
    }

    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        $pdo = Database::getInstance();
        $rows = $pdo->query("
            SELECT a.appointment_id,
                   a.appointment_datetime,
                   a.status,
                   a.reason,
                   p.full_name  AS patient_name,
                   d.full_name  AS doctor_name,
                   dep.name     AS department_name
            FROM   Appointment a
            JOIN   Patient    p   ON a.patient_id    = p.patient_id
            JOIN   Doctor     d   ON a.doctor_id     = d.doctor_id
            JOIN   Department dep ON a.department_id = dep.department_id
            ORDER  BY a.appointment_datetime DESC
            LIMIT  200
        ")->fetchAll(PDO::FETCH_ASSOC);

        Response::success($rows, 'تم جلب المواعيد');
    }

    public function available(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        $doctorId = Request::query('doctor_id');
        $date = Request::query('date');

        if (empty($doctorId) || empty($date)) {
            Response::error(
                'المعاملات المطلوبة: doctor_id و date.',
                ['doctor_id' => 'مطلوب', 'date' => 'مطلوب'],
                422
            );
        }

        if (!filter_var($doctorId, FILTER_VALIDATE_INT)) {
            Response::error('doctor_id يجب أن يكون عدداً صحيحاً.', null, 422);
        }

        try {
            $slots = $this->service->getAvailableSlots((int) $doctorId, (string) $date);
            Response::success($slots, 'تم جلب المواعيد المتاحة بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function store(): void
    {
        if (!Request::isMethod('POST')) {
            Response::methodNotAllowed();
        }

        $body = Request::body();
        if (empty($body)) {
            Response::error('لم يتم إرسال بيانات. تأكد من إرسال JSON في body الطلب.', null, 400);
        }

        $patient = SessionAuth::requirePatient();
        $body['patient_id'] = (int) $patient['patient_id'];

        try {
            $appointment = $this->service->book($body);
            Response::success($appointment, 'تم حجز الموعد بنجاح', 201);
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

    public function cancel(int $id): void
    {
        if (!Request::isMethod('PUT')) {
            Response::methodNotAllowed();
        }

        $patient = SessionAuth::requirePatient();

        try {
            $appointment = $this->service->cancel($id, (int) $patient['patient_id']);
            Response::success($appointment, 'تم إلغاء الموعد بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }

    public function reschedule(int $id): void
    {
        if (!Request::isMethod('PUT')) {
            Response::methodNotAllowed();
        }

        $patient = SessionAuth::requirePatient();
        $body = Request::body();
        $newDatetime = trim((string) ($body['appointment_datetime'] ?? ''));

        if ($newDatetime === '') {
            Response::error('appointment_datetime مطلوب بصيغة YYYY-MM-DD HH:MM:SS', null, 422);
        }

        try {
            $appointment = $this->service->reschedule($id, (int) $patient['patient_id'], $newDatetime);
            Response::success($appointment, 'تم تعديل الموعد بنجاح');
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), null, 422);
        } catch (Throwable $e) {
            Response::serverError($e->getMessage());
        }
    }
}
