<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Service.php';

/**
 * ServiceRepository
 * -----------------
 * استعلامات جدول Service مع JOIN اختياري على Department.
 */
class ServiceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // Base SELECT
    // -------------------------------------------------------

    private function baseSelect(): string
    {
        return '
            SELECT s.service_id,
                   s.name,
                   s.description,
                   s.base_cost,
                   s.department_id,
                   d.name AS department_name
            FROM   Service s
            JOIN   Department d ON d.department_id = s.department_id
        ';
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب جميع الخدمات.
     *
     * @return Service[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' ORDER BY s.service_id ASC'
        );
        $stmt->execute();

        return array_map(fn(array $row) => new Service($row), $stmt->fetchAll());
    }

    /**
     * جلب خدمة واحدة بمعرّفها.
     *
     * @param  int $id
     * @return Service|null
     */
    public function findById(int $id): ?Service
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE s.service_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new Service($row) : null;
    }

    public function existsById(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM Service WHERE service_id = :id');
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * جلب خدمات قسم معين.
     *
     * @param  int $departmentId
     * @return Service[]
     */
    public function findByDepartmentId(int $departmentId): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . ' WHERE s.department_id = :dept_id ORDER BY s.service_id ASC'
        );
        $stmt->execute([':dept_id' => $departmentId]);

        return array_map(fn(array $row) => new Service($row), $stmt->fetchAll());
    }

    /**
     * البحث في أسماء الخدمات أو وصفها (جزئي — LIKE).
     * يُستخدم من ChatRouterService.
     *
     * @param  string $keyword
     * @return Service[]
     */
    public function searchByNameOrDescription(string $keyword): array
    {
        $stmt = $this->pdo->prepare(
            $this->baseSelect() . '
            WHERE s.name        LIKE :kw
               OR s.description LIKE :kw2
            ORDER BY s.service_id ASC
        ');
        $stmt->execute([
            ':kw'  => '%' . $keyword . '%',
            ':kw2' => '%' . $keyword . '%',
        ]);

        return array_map(fn(array $row) => new Service($row), $stmt->fetchAll());
    }
}
