<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Doctor.php';

/**
 * DoctorRepository
 * ----------------
 * استعلامات جدول Doctor مع JOIN اختياري على Department.
 */
class DoctorRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // Base SELECT — يُعيد صفوفاً مع اسم القسم دائماً
    // -------------------------------------------------------

    private function baseSelect(): string
    {
        return '
            SELECT d.doctor_id,
                   d.full_name,
                   d.specialty,
                   d.phone,
                   d.email,
                   d.department_id,
                   dep.name AS department_name
            FROM   Doctor d
            JOIN   Department dep ON dep.department_id = d.department_id
        ';
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب جميع الأطباء مع اسم قسمهم.
     *
     * @return Doctor[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' ORDER BY d.doctor_id ASC'
        );
        $stmt->execute();

        return array_map(fn(array $row) => new Doctor($row), $stmt->fetchAll());
    }

    /**
     * جلب طبيب واحد بمعرّفه.
     *
     * @param  int $id
     * @return Doctor|null
     */
    public function findById(int $id): ?Doctor
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE d.doctor_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new Doctor($row) : null;
    }

    /**
     * جلب أطباء قسم معين.
     *
     * @param  int $departmentId
     * @return Doctor[]
     */
    public function findByDepartmentId(int $departmentId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE d.department_id = :dept_id ORDER BY d.doctor_id ASC'
        );
        $stmt->execute([':dept_id' => $departmentId]);

        return array_map(fn(array $row) => new Doctor($row), $stmt->fetchAll());
    }

    /**
     * جلب أطباء حسب التخصص (جزئي — LIKE).
     *
     * @param  string $specialty
     * @return Doctor[]
     */
    public function findBySpecialty(string $specialty): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE d.specialty LIKE :specialty ORDER BY d.doctor_id ASC'
        );
        $stmt->execute([':specialty' => '%' . $specialty . '%']);

        return array_map(fn(array $row) => new Doctor($row), $stmt->fetchAll());
    }

    /**
     * البحث في أسماء الأطباء أو تخصصاتهم (جزئي — LIKE).
     * يُستخدم من ChatRouterService.
     *
     * @param  string $keyword
     * @return Doctor[]
     */
    public function searchByNameOrSpecialty(string $keyword): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE d.full_name LIKE :kw
               OR d.specialty  LIKE :kw2
            ORDER BY d.doctor_id ASC
        ');
        $stmt->execute([
            ':kw'  => '%' . $keyword . '%',
            ':kw2' => '%' . $keyword . '%',
        ]);

        return array_map(fn(array $row) => new Doctor($row), $stmt->fetchAll());
    }

    /**
     * التحقق من وجود طبيب بمعرّف معين (للـ validation).
     *
     * @param  int $id
     * @return bool
     */
    public function existsById(int $id): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM Doctor WHERE doctor_id = :id
        ');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
