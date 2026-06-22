<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/AdminAuth.php';

class AdminController
{
    // ===================== AUTH =====================

    public function login(): void
    {
        $body     = Request::body();
        $username = trim((string) ($body['email'] ?? $body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::error('اسم المستخدم وكلمة المرور مطلوبان.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM AdminUser WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || !password_verify($password, $admin['password_hash'] ?? '')) {
            Response::error('البريد الإلكتروني أو كلمة المرور غير صحيحة.', null, 401);
        }

        $pdo->prepare("UPDATE AdminUser SET last_login = NOW() WHERE admin_id = ?")->execute([$admin['admin_id']]);
        AdminAuth::login($admin);

        unset($admin['password_hash']);
        Response::success($admin, 'تم تسجيل دخول الأدمن بنجاح');
    }

    public function logout(): void
    {
        AdminAuth::logout();
        Response::success(null, 'تم تسجيل الخروج');
    }

    public function me(): void
    {
        Response::success(AdminAuth::require(), 'بيانات الأدمن');
    }

    // ===================== STATS =====================

    public function stats(): void
    {
        AdminAuth::require();
        $pdo = Database::getInstance();

        $data = [
            'doctors'      => (int) $pdo->query("SELECT COUNT(*) FROM Doctor")->fetchColumn(),
            'patients'     => (int) $pdo->query("SELECT COUNT(*) FROM Patient")->fetchColumn(),
            'departments'  => (int) $pdo->query("SELECT COUNT(*) FROM Department")->fetchColumn(),
            'appointments' => (int) $pdo->query("SELECT COUNT(*) FROM Appointment")->fetchColumn(),
            'lab_tests'    => (int) $pdo->query("SELECT COUNT(*) FROM LabTest")->fetchColumn(),
            'pending_labs' => (int) $pdo->query("SELECT COUNT(*) FROM LabTest WHERE status='Pending'")->fetchColumn(),
            'invoices'     => (int) $pdo->query("SELECT COUNT(*) FROM Invoice")->fetchColumn(),
            'revenue'      => (float) $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM Invoice WHERE status='Paid'")->fetchColumn(),
            'feedback'     => (int) $pdo->query("SELECT COUNT(*) FROM Feedback WHERE status='New'")->fetchColumn(),
        ];

        Response::success($data, 'إحصائيات النظام');
    }

    public function statsDetailed(): void
    {
        AdminAuth::require();
        $pdo = Database::getInstance();

        // Appointments by status
        $apptByStatus = $pdo->query("
            SELECT status, COUNT(*) AS total FROM Appointment GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Appointments by department
        $apptByDept = $pdo->query("
            SELECT dep.name AS department, COUNT(a.appointment_id) AS total
            FROM Appointment a
            JOIN Doctor d ON a.doctor_id = d.doctor_id
            JOIN Department dep ON d.department_id = dep.department_id
            GROUP BY dep.department_id, dep.name
            ORDER BY total DESC LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Appointments per month (last 6 months)
        $apptByMonth = $pdo->query("
            SELECT DATE_FORMAT(appointment_datetime,'%Y-%m') AS month,
                   COUNT(*) AS total
            FROM Appointment
            WHERE appointment_datetime >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY month ORDER BY month
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Doctors by department
        $doctorsByDept = $pdo->query("
            SELECT dep.name AS department, COUNT(d.doctor_id) AS total
            FROM Doctor d
            JOIN Department dep ON d.department_id = dep.department_id
            GROUP BY dep.department_id, dep.name
            ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Lab tests by status
        $labByStatus = $pdo->query("
            SELECT status, COUNT(*) AS total FROM LabTest GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Invoice status distribution
        $invoiceByStatus = $pdo->query("
            SELECT status, COUNT(*) AS total, COALESCE(SUM(total_amount),0) AS amount
            FROM Invoice GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'appt_by_status'   => $apptByStatus,
            'appt_by_dept'     => $apptByDept,
            'appt_by_month'    => $apptByMonth,
            'doctors_by_dept'  => $doctorsByDept,
            'lab_by_status'    => $labByStatus,
            'invoice_by_status'=> $invoiceByStatus,
        ], 'إحصائيات مفصّلة');
    }

    // ===================== DOCTORS =====================

    public function listDoctors(): void
    {
        AdminAuth::require();
        $pdo  = Database::getInstance();
        $rows = $pdo->query("
            SELECT d.doctor_id, d.full_name, d.specialty, d.email, d.phone,
                   dep.name AS department_name, d.department_id
            FROM Doctor d LEFT JOIN Department dep ON d.department_id = dep.department_id
            ORDER BY d.doctor_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة الأطباء');
    }

    public function createDoctor(): void
    {
        AdminAuth::require();
        $body = Request::body();
        $name     = trim((string) ($body['full_name'] ?? ''));
        $specialty= trim((string) ($body['specialty'] ?? ''));
        $email    = trim((string) ($body['email'] ?? ''));
        $phone    = trim((string) ($body['phone'] ?? ''));
        $deptId   = !empty($body['department_id']) ? (int) $body['department_id'] : null;
        $password = (string) ($body['password'] ?? 'Doctor@123');

        if ($name === '' || $specialty === '') {
            Response::error('الاسم والتخصص مطلوبان.', null, 422);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO Doctor (full_name, specialty, email, phone, department_id, password_hash) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$name, $specialty, $email, $phone, $deptId, $hash]);

        Response::success(['doctor_id' => $pdo->lastInsertId()], 'تم إضافة الطبيب بنجاح', 201);
    }

    public function updateDoctor(int $id): void
    {
        AdminAuth::require();
        $body     = Request::body();
        $fields   = [];
        $params   = [];

        foreach (['full_name', 'specialty', 'email', 'phone'] as $f) {
            if (isset($body[$f])) { $fields[] = "$f = ?"; $params[] = trim((string) $body[$f]); }
        }
        if (isset($body['department_id'])) { $fields[] = "department_id = ?"; $params[] = (int) $body['department_id']; }
        if (!empty($body['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash((string) $body['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);

        $params[] = $id;
        Database::getInstance()->prepare("UPDATE Doctor SET " . implode(',', $fields) . " WHERE doctor_id = ?")->execute($params);
        Response::success(null, 'تم تحديث بيانات الطبيب');
    }

    public function deleteDoctor(int $id): void
    {
        AdminAuth::requireSuperAdmin();
        Database::getInstance()->prepare("DELETE FROM Doctor WHERE doctor_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف الطبيب');
    }

    // ===================== PATIENTS =====================

    public function listPatients(): void
    {
        AdminAuth::require();
        $rows = Database::getInstance()->query("
            SELECT patient_id, full_name, national_id, phone, email, gender, date_of_birth, created_at
            FROM Patient ORDER BY patient_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة المرضى');
    }

    public function createPatient(): void
    {
        AdminAuth::require();
        $body = Request::body();
        $name   = trim((string) ($body['full_name'] ?? ''));
        $phone  = trim((string) ($body['phone'] ?? ''));
        $email  = trim((string) ($body['email'] ?? ''));
        $gender = trim((string) ($body['gender'] ?? 'Male'));
        $dob    = !empty($body['date_of_birth']) ? $body['date_of_birth'] : null;
        $nid    = !empty($body['national_id']) ? trim($body['national_id']) : null;

        if ($name === '' || $phone === '') {
            Response::error('الاسم ورقم الهاتف مطلوبان.', null, 422);
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO Patient (full_name, national_id, phone, email, gender, date_of_birth) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$name, $nid, $phone, $email, $gender, $dob]);
        Response::success(['patient_id' => $pdo->lastInsertId()], 'تم إضافة المريض بنجاح', 201);
    }

    public function updatePatient(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];

        foreach (['full_name', 'national_id', 'phone', 'email', 'gender', 'date_of_birth'] as $f) {
            if (array_key_exists($f, $body)) { $fields[] = "$f = ?"; $params[] = $body[$f] === '' ? null : $body[$f]; }
        }

        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE Patient SET " . implode(',', $fields) . " WHERE patient_id = ?")->execute($params);
        Response::success(null, 'تم تحديث بيانات المريض');
    }

    public function deletePatient(int $id): void
    {
        AdminAuth::requireSuperAdmin();
        Database::getInstance()->prepare("DELETE FROM Patient WHERE patient_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف المريض');
    }

    // ===================== DEPARTMENTS =====================

    public function listDepartments(): void
    {
        AdminAuth::require();
        $rows = Database::getInstance()->query("SELECT * FROM Department ORDER BY department_id")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة الأقسام');
    }

    public function createDepartment(): void
    {
        AdminAuth::require();
        $body  = Request::body();
        $name  = trim((string) ($body['name'] ?? ''));
        $loc   = trim((string) ($body['location'] ?? ''));
        $hours = trim((string) ($body['working_hours'] ?? ''));

        if ($name === '') Response::error('اسم القسم مطلوب.', null, 422);

        $pdo  = Database::getInstance();
        $pdo->prepare("INSERT INTO Department (name, location, working_hours) VALUES (?,?,?)")->execute([$name, $loc, $hours]);
        Response::success(['department_id' => $pdo->lastInsertId()], 'تم إضافة القسم', 201);
    }

    public function updateDepartment(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];
        foreach (['name', 'location', 'working_hours'] as $f) {
            if (isset($body[$f])) { $fields[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE Department SET " . implode(',', $fields) . " WHERE department_id = ?")->execute($params);
        Response::success(null, 'تم تحديث القسم');
    }

    public function deleteDepartment(int $id): void
    {
        AdminAuth::requireSuperAdmin();
        Database::getInstance()->prepare("DELETE FROM Department WHERE department_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف القسم');
    }

    // ===================== APPOINTMENTS =====================

    public function listAppointments(): void
    {
        AdminAuth::require();
        $pdo    = Database::getInstance();
        $status = Request::query('status');
        $sql    = "SELECT a.appointment_id, a.appointment_datetime, a.status, a.reason,
                          p.full_name AS patient_name, p.phone AS patient_phone,
                          d.full_name AS doctor_name, dep.name AS department_name
                   FROM Appointment a
                   JOIN Patient p ON a.patient_id = p.patient_id
                   JOIN Doctor d ON a.doctor_id = d.doctor_id
                   JOIN Department dep ON a.department_id = dep.department_id";
        $params = [];
        if ($status) { $sql .= " WHERE a.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY a.appointment_datetime DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC), 'قائمة المواعيد');
    }

    public function updateAppointment(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];
        foreach (['status', 'reason', 'appointment_datetime'] as $f) {
            if (isset($body[$f])) { $fields[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE Appointment SET " . implode(',', $fields) . " WHERE appointment_id = ?")->execute($params);
        Response::success(null, 'تم تحديث الموعد');
    }

    public function deleteAppointment(int $id): void
    {
        AdminAuth::requireSuperAdmin();
        Database::getInstance()->prepare("DELETE FROM Appointment WHERE appointment_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف الموعد');
    }

    // ===================== LAB TESTS =====================

    public function listLabTests(): void
    {
        AdminAuth::require();
        $pdo    = Database::getInstance();
        $status = Request::query('status');
        $sql    = "SELECT lt.lab_test_id, lt.test_name, lt.test_date, lt.result_text, lt.status,
                          p.full_name AS patient_name, p.email AS patient_email,
                          d.full_name AS doctor_name
                   FROM LabTest lt
                   JOIN Patient p ON lt.patient_id = p.patient_id
                   LEFT JOIN Doctor d ON lt.ordered_by_doctor_id = d.doctor_id";
        $params = [];
        if ($status) { $sql .= " WHERE lt.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY lt.test_date DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC), 'قائمة الفحوصات');
    }

    public function deleteLabTest(int $id): void
    {
        AdminAuth::requireSuperAdmin();
        Database::getInstance()->prepare("DELETE FROM LabTest WHERE lab_test_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف الفحص');
    }

    // ===================== INVOICES =====================

    public function listInvoices(): void
    {
        AdminAuth::require();
        $rows = Database::getInstance()->query("
            SELECT i.invoice_id, i.issue_date, i.total_amount, i.status,
                   p.full_name AS patient_name, p.phone AS patient_phone
            FROM Invoice i JOIN Patient p ON i.patient_id = p.patient_id
            ORDER BY i.issue_date DESC LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة الفواتير');
    }

    public function updateInvoice(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];
        foreach (['status', 'total_amount'] as $f) {
            if (isset($body[$f])) { $fields[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE Invoice SET " . implode(',', $fields) . " WHERE invoice_id = ?")->execute($params);
        Response::success(null, 'تم تحديث الفاتورة');
    }

    // ===================== FEEDBACK =====================

    public function listFeedback(): void
    {
        AdminAuth::require();
        $rows = Database::getInstance()->query("
            SELECT f.feedback_id, f.type, f.message, f.status, f.created_at,
                   p.full_name AS patient_name
            FROM Feedback f LEFT JOIN Patient p ON f.patient_id = p.patient_id
            ORDER BY f.created_at DESC LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة الملاحظات');
    }

    public function updateFeedback(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $status = trim((string) ($body['status'] ?? ''));
        if (!in_array($status, ['New', 'InReview', 'Closed'], true)) {
            Response::error('حالة غير صحيحة.', null, 422);
        }
        Database::getInstance()->prepare("UPDATE Feedback SET status = ? WHERE feedback_id = ?")->execute([$status, $id]);
        Response::success(null, 'تم تحديث حالة الملاحظة');
    }

    // ===================== LAB USERS =====================

    public function listLabUsers(): void
    {
        AdminAuth::require();
        $rows = Database::getInstance()->query("SELECT lab_user_id, username, full_name, is_active, created_at FROM LabUser ORDER BY lab_user_id")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة مستخدمي المختبر');
    }

    public function createLabUser(): void
    {
        AdminAuth::require();
        $body     = Request::body();
        $username = trim((string) ($body['username'] ?? ''));
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $password = (string) ($body['password'] ?? 'Lab@123');

        if ($username === '' || $fullName === '') Response::error('اسم المستخدم والاسم الكامل مطلوبان.', null, 422);

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo  = Database::getInstance();
        $pdo->prepare("INSERT INTO LabUser (username, full_name, password_hash) VALUES (?,?,?)")->execute([$username, $fullName, $hash]);
        Response::success(['lab_user_id' => $pdo->lastInsertId()], 'تم إضافة مستخدم المختبر', 201);
    }

    public function updateLabUser(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];
        foreach (['username', 'full_name'] as $f) {
            if (isset($body[$f])) { $fields[] = "$f = ?"; $params[] = $body[$f]; }
        }
        if (isset($body['is_active'])) { $fields[] = "is_active = ?"; $params[] = (int) $body['is_active']; }
        if (!empty($body['password'])) { $fields[] = "password_hash = ?"; $params[] = password_hash($body['password'], PASSWORD_BCRYPT); }
        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE LabUser SET " . implode(',', $fields) . " WHERE lab_user_id = ?")->execute($params);
        Response::success(null, 'تم تحديث مستخدم المختبر');
    }

    // ===================== NEWS =====================

    public function listNews(): void
    {
        $pdo  = Database::getInstance();
        $rows = $pdo->query("
            SELECT n.news_id, n.title, n.content, n.image_url,
                   n.published_at, n.is_published, n.created_at, n.updated_at,
                   a.username AS created_by_name
            FROM News n
            LEFT JOIN AdminUser a ON a.admin_id = n.created_by
            ORDER BY n.published_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'قائمة الأخبار');
    }

    public function listPublishedNews(): void
    {
        $pdo  = Database::getInstance();
        $rows = $pdo->query("
            SELECT news_id, title, content, image_url, published_at, created_at
            FROM News
            WHERE is_published = 1
            ORDER BY published_at DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        Response::success($rows, 'الأخبار المنشورة');
    }

    public function createNews(): void
    {
        $admin = AdminAuth::require();
        $body  = Request::body();
        $title = trim((string) ($body['title'] ?? ''));
        $content = trim((string) ($body['content'] ?? ''));
        if ($title === '' || $content === '') {
            Response::error('العنوان والمحتوى مطلوبان.', null, 422);
        }
        $imageUrl    = trim((string) ($body['image_url'] ?? '')) ?: null;
        $publishedAt = trim((string) ($body['published_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $isPublished = isset($body['is_published']) ? (int) $body['is_published'] : 1;
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("
            INSERT INTO News (title, content, image_url, published_at, is_published, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $content, $imageUrl, $publishedAt, $isPublished, $admin['admin_id']]);
        $newsId = (int) $pdo->lastInsertId();
        Response::success(['news_id' => $newsId], 'تم إنشاء الخبر بنجاح', 201);
    }

    public function updateNews(int $id): void
    {
        AdminAuth::require();
        $body   = Request::body();
        $fields = [];
        $params = [];
        foreach (['title', 'content', 'image_url', 'published_at'] as $f) {
            if (array_key_exists($f, $body)) {
                $fields[] = "$f = ?";
                $params[] = trim((string) $body[$f]) ?: null;
            }
        }
        if (array_key_exists('is_published', $body)) {
            $fields[] = "is_published = ?";
            $params[] = (int) $body['is_published'];
        }
        if (empty($fields)) Response::error('لا توجد بيانات للتحديث.', null, 422);
        $params[] = $id;
        Database::getInstance()->prepare("UPDATE News SET " . implode(', ', $fields) . " WHERE news_id = ?")->execute($params);
        Response::success(null, 'تم تحديث الخبر');
    }

    public function deleteNews(int $id): void
    {
        AdminAuth::require();
        Database::getInstance()->prepare("DELETE FROM News WHERE news_id = ?")->execute([$id]);
        Response::success(null, 'تم حذف الخبر');
    }
}
