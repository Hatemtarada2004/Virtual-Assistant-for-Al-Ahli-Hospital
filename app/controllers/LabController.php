<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/LabAuth.php';

class LabController
{
    public function login(): void
    {
        $body     = Request::body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('اسم المستخدم وكلمة المرور مطلوبان.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM LabUser WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('اسم المستخدم أو كلمة المرور غير صحيحة.', null, 401);
        }

        LabAuth::login($user);
        unset($user['password_hash']);
        Response::success($user, 'تم تسجيل دخول المختبر بنجاح');
    }

    public function logout(): void
    {
        LabAuth::logout();
        Response::success(null, 'تم تسجيل الخروج');
    }

    public function me(): void
    {
        Response::success(LabAuth::require(), 'بيانات المختبر');
    }

    public function listTests(): void
    {
        LabAuth::require();
        $pdo    = Database::getInstance();
        $status = Request::query('status');

        $sql = "SELECT lt.lab_test_id, lt.test_name, lt.test_date, lt.result_text, lt.status,
                       p.full_name AS patient_name, p.phone AS patient_phone, p.email AS patient_email,
                       p.national_id,
                       d.full_name AS doctor_name
                FROM LabTest lt
                JOIN Patient p ON lt.patient_id = p.patient_id
                LEFT JOIN Doctor d ON lt.ordered_by_doctor_id = d.doctor_id";
        $params = [];

        if ($status) {
            $sql .= " WHERE lt.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY lt.test_date DESC LIMIT 300";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC), 'قائمة الفحوصات');
    }

    public function updateResult(int $id): void
    {
        LabAuth::require();
        $body   = Request::body();
        $result = trim((string) ($body['result_text'] ?? ''));
        $status = trim((string) ($body['status'] ?? 'Ready'));

        if ($result === '') {
            Response::error('نص النتيجة مطلوب.', null, 422);
        }
        if (!in_array($status, ['Pending', 'Ready'], true)) {
            $status = 'Ready';
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("UPDATE LabTest SET result_text = ?, status = ? WHERE lab_test_id = ?");
        $stmt->execute([$result, $status, $id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound('الفحص غير موجود.');
        }

        // جلب بيانات الفحص والمريض لإرسال الإيميل
        $stmt2 = $pdo->prepare("
            SELECT lt.*, p.full_name AS patient_name, p.email AS patient_email
            FROM LabTest lt JOIN Patient p ON lt.patient_id = p.patient_id
            WHERE lt.lab_test_id = ?
        ");
        $stmt2->execute([$id]);
        $test = $stmt2->fetch(PDO::FETCH_ASSOC);

        // إرسال إيميل للمريض إذا أصبحت النتيجة Ready
        if ($status === 'Ready' && !empty($test['patient_email'])) {
            $this->sendResultEmail($test);
        }

        Response::success($test, 'تم تحديث نتيجة الفحص بنجاح');
    }

    public function addTest(): void
    {
        LabAuth::require();
        $body      = Request::body();
        $patientId = (int) ($body['patient_id'] ?? 0);
        $testName  = trim((string) ($body['test_name'] ?? ''));
        $testDate  = trim((string) ($body['test_date'] ?? date('Y-m-d')));
        $doctorId  = !empty($body['ordered_by_doctor_id']) ? (int) $body['ordered_by_doctor_id'] : null;

        if ($patientId <= 0 || $testName === '') {
            Response::error('معرف المريض واسم الفحص مطلوبان.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO LabTest (patient_id, ordered_by_doctor_id, test_name, test_date, status) VALUES (?, ?, ?, ?, 'Pending')");
        $stmt->execute([$patientId, $doctorId, $testName, $testDate]);

        Response::success(['lab_test_id' => $pdo->lastInsertId()], 'تم إضافة الفحص بنجاح', 201);
    }

    private function sendResultEmail(array $test): void
    {
        try {
            require_once __DIR__ . '/../services/SmtpMailer.php';
            require_once __DIR__ . '/../config/load_env.php';

            $cfg  = load_env();
            $mail = new SmtpMailer($cfg);

            $subject = "نتيجة فحص: " . $test['test_name'];
            $body    = "
<div dir='rtl' style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #ddd;border-radius:8px'>
  <h2 style='color:#1a5276'>مستشفى الأهلي - نتيجة فحص مختبري</h2>
  <p>عزيزنا/عزيزتنا <strong>{$test['patient_name']}</strong>،</p>
  <p>نود إعلامكم بأن نتيجة الفحص المختبري التالي أصبحت متاحة:</p>
  <table style='width:100%;border-collapse:collapse;margin:15px 0'>
    <tr style='background:#f2f3f4'><td style='padding:10px;border:1px solid #ddd'><strong>الفحص</strong></td><td style='padding:10px;border:1px solid #ddd'>{$test['test_name']}</td></tr>
    <tr><td style='padding:10px;border:1px solid #ddd'><strong>التاريخ</strong></td><td style='padding:10px;border:1px solid #ddd'>{$test['test_date']}</td></tr>
    <tr style='background:#f2f3f4'><td style='padding:10px;border:1px solid #ddd'><strong>النتيجة</strong></td><td style='padding:10px;border:1px solid #ddd'>{$test['result_text']}</td></tr>
  </table>
  <p style='color:#7f8c8d;font-size:12px'>للاستفسار تواصل مع المستشفى على الرقم: 02-2222222</p>
</div>";

            $mail->send($test['patient_email'], $test['patient_name'], $subject, $body);
        } catch (Throwable $e) {
            error_log("LabResult email failed: " . $e->getMessage());
        }
    }
}
