<?php

require_once __DIR__ . '/../repositories/DepartmentRepository.php';

/**
 * DepartmentService
 * -----------------
 * Business logic لعمليات الأقسام.
 */
class DepartmentService
{
    private DepartmentRepository $repo;

    public function __construct()
    {
        $this->repo = new DepartmentRepository();
    }

    /**
     * جلب جميع الأقسام كمصفوفات جاهزة للـ JSON.
     *
     * @return array[]
     */
    public function getAll(): array
    {
        $departments = $this->repo->findAll();
        return array_map(fn($d) => $d->toArray(), $departments);
    }

    /**
     * جلب قسم واحد بمعرّفه.
     * يُعيد null إذا لم يوجد.
     *
     * @param  int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $department = $this->repo->findById($id);
        return $department?->toArray();
    }

    /**
     * البحث في أسماء الأقسام بكلمة مفتاحية.
     * يُستخدم من ChatController عند intent = ask_departments.
     *
     * @param  string $keyword
     * @return array[]
     */
    public function search(string $keyword): array
    {
        $departments = $this->repo->searchByName($keyword);
        return array_map(fn($d) => $d->toArray(), $departments);
    }
}
