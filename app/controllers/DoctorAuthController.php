<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/DoctorAuth.php';

class DoctorAuthController
{
    public function login(): void
    {
        $body = Request::body();
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error('البريد الإلكتروني وكلمة المرور مطلوبان.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT doctor_id, full_name, specialty, email, phone, department_id, password_hash FROM Doctor WHERE email = ?");
        $stmt->execute([$email]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doctor || !password_verify($password, $doctor['password_hash'] ?? '')) {
            Response::error('البريد الإلكتروني أو كلمة المرور غير صحيحة.', null, 401);
        }

        DoctorAuth::login($doctor);

        unset($doctor['password_hash']);
        Response::success($doctor, 'تم تسجيل دخول الطبيب بنجاح');
    }

    public function logout(): void
    {
        DoctorAuth::logout();
        Response::success(null, 'تم تسجيل الخروج');
    }

    public function me(): void
    {
        $doctor = DoctorAuth::require();
        Response::success($doctor, 'بيانات الطبيب');
    }

    public function myAppointments(): void
    {
        $doctor = DoctorAuth::require();
        $pdo    = Database::getInstance();

        $status = Request::query('status');
        $date   = Request::query('date');

        $sql = "SELECT a.appointment_id, a.appointment_datetime, a.status, a.reason,
                       p.full_name AS patient_name, p.phone AS patient_phone, p.email AS patient_email,
                       dep.name AS department_name
                FROM Appointment a
                JOIN Patient p ON a.patient_id = p.patient_id
                JOIN Department dep ON a.department_id = dep.department_id
                WHERE a.doctor_id = ?";
        $params = [$doctor['doctor_id']];

        if ($status) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        if ($date) {
            $sql .= " AND DATE(a.appointment_datetime) = ?";
            $params[] = $date;
        }

        $sql .= " ORDER BY a.appointment_datetime DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC), 'مواعيد الطبيب');
    }

    public function changePassword(): void
    {
        $doctor  = DoctorAuth::require();
        $body    = Request::body();
        $current = (string) ($body['current_password'] ?? '');
        $new     = (string) ($body['new_password'] ?? '');

        if (strlen($new) < 6) {
            Response::error('كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT password_hash FROM Doctor WHERE doctor_id = ?");
        $stmt->execute([$doctor['doctor_id']]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password_hash'] ?? '')) {
            Response::error('كلمة المرور الحالية غير صحيحة.', null, 401);
        }

        $hash = password_hash($new, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE Doctor SET password_hash = ? WHERE doctor_id = ?")->execute([$hash, $doctor['doctor_id']]);
        Response::success(null, 'تم تغيير كلمة المرور بنجاح');
    }
}
