<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Department.php';

/**
 * DepartmentRepository
 * --------------------
 * استعلامات جدول Department فقط.
 */
class DepartmentRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب جميع الأقسام.
     *
     * @return Department[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT department_id, name, location, working_hours
            FROM   Department
            ORDER  BY department_id ASC
        ');
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_map(fn(array $row) => new Department($row), $rows);
    }

    /**
     * جلب قسم واحد بمعرّفه.
     *
     * @param  int $id
     * @return Department|null
     */
    public function findById(int $id): ?Department
    {
        $stmt = $this->pdo->prepare('
            SELECT department_id, name, location, working_hours
            FROM   Department
            WHERE  department_id = :id
            LIMIT  1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        return $row ? new Department($row) : null;
    }

    /**
     * البحث في أسماء الأقسام (جزئي — LIKE).
     * يُستخدم من قِبَل ChatRouterService.
     *
     * @param  string $keyword
     * @return Department[]
     */
    public function searchByName(string $keyword): array
    {
        $stmt = $this->pdo->prepare('
            SELECT department_id, name, location, working_hours
            FROM   Department
            WHERE  name LIKE :keyword
            ORDER  BY department_id ASC
        ');
        $stmt->execute([':keyword' => '%' . $keyword . '%']);

        $rows = $stmt->fetchAll();
        return array_map(fn(array $row) => new Department($row), $rows);
    }
}
