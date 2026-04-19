<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Request.php';
require_once __DIR__ . '/../helpers/Response.php';

/**
 * StatsController
 * ---------------
 * GET /api/stats
 * يُعيد إحصائيات شاملة عن المستشفى من قاعدة البيانات.
 */
class StatsController
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    public function index(): void
    {
        if (!Request::isMethod('GET')) {
            Response::methodNotAllowed();
        }

        Response::success([
            'overview'       => $this->overview(),
            'appointments'   => $this->appointments(),
            'invoices'       => $this->invoices(),
            'lab_tests'      => $this->labTests(),
            'occupancy_rate' => $this->occupancyRate(),
        ], 'تم جلب الإحصائيات بنجاح');
    }

    // ── نظرة عامة ──────────────────────────────────────────────

    private function overview(): array
    {
        return [
            'doctors'     => $this->count('Doctor'),
            'patients'    => $this->count('Patient'),
            'departments' => $this->count('Department'),
            'services'    => $this->count('Service'),
            'feedbacks'   => $this->count('Feedback'),
        ];
    }

    // ── المواعيد ────────────────────────────────────────────────

    private function appointments(): array
    {
        $row = $this->pdo->query("
            SELECT
                COUNT(*)                                     AS total,
                SUM(status = 'Booked')                       AS booked,
                SUM(status = 'Completed')                    AS completed,
                SUM(status = 'Cancelled')                    AS cancelled,
                SUM(status = 'NoShow')                       AS noshow,
                SUM(DATE(appointment_datetime) = CURDATE())  AS today
            FROM Appointment
        ")->fetch(PDO::FETCH_ASSOC);

        return array_map('intval', $row);
    }

    // ── الفواتير ────────────────────────────────────────────────

    private function invoices(): array
    {
        $row = $this->pdo->query("
            SELECT
                COUNT(*)                                                              AS total,
                SUM(status = 'Paid')                                                  AS paid,
                SUM(status = 'Unpaid')                                                AS unpaid,
                SUM(status = 'PartiallyPaid')                                         AS partial,
                COALESCE(SUM(CASE WHEN status = 'Paid'  THEN total_amount END), 0)    AS revenue,
                COALESCE(SUM(CASE WHEN status != 'Paid' THEN total_amount END), 0)    AS outstanding
            FROM Invoice
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'total'       => (int)   $row['total'],
            'paid'        => (int)   $row['paid'],
            'unpaid'      => (int)   $row['unpaid'],
            'partial'     => (int)   $row['partial'],
            'revenue'     => (float) $row['revenue'],
            'outstanding' => (float) $row['outstanding'],
        ];
    }

    // ── التحاليل ────────────────────────────────────────────────

    private function labTests(): array
    {
        $row = $this->pdo->query("
            SELECT COUNT(*)              AS total,
                   SUM(status='Ready')   AS ready,
                   SUM(status='Pending') AS pending
            FROM LabTest
        ")->fetch(PDO::FETCH_ASSOC);

        return array_map('intval', $row);
    }

    // ── نسبة الإشغال ────────────────────────────────────────────

    private function occupancyRate(): int
    {
        $booked = (int) $this->pdo->query("
            SELECT COUNT(*) FROM Appointment
            WHERE status = 'Booked' AND appointment_datetime >= NOW()
        ")->fetchColumn();

        $doctors  = $this->count('Doctor');
        $capacity = $doctors * 5;   // افتراض: 5 مواعيد/طبيب/يوم

        return $capacity > 0 ? min(100, (int) round($booked / $capacity * 100)) : 0;
    }

    // ── مساعد ───────────────────────────────────────────────────

    private function count(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
