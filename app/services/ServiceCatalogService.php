<?php

require_once __DIR__ . '/../repositories/ServiceRepository.php';

/**
 * ServiceCatalogService
 * ---------------------
 * Business logic لكتالوج الخدمات الطبية.
 */
class ServiceCatalogService
{
    private ServiceRepository $repo;

    public function __construct()
    {
        $this->repo = new ServiceRepository();
    }

    /**
     * جلب جميع الخدمات.
     *
     * @return array[]
     */
    public function getAll(): array
    {
        return array_map(fn($s) => $s->toArray(), $this->repo->findAll());
    }

    /**
     * جلب خدمة واحدة بمعرّفها.
     *
     * @param  int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        return $this->repo->findById($id)?->toArray();
    }

    /**
     * جلب خدمات قسم معين.
     *
     * @param  int $departmentId
     * @return array[]
     */
    public function getByDepartment(int $departmentId): array
    {
        return array_map(fn($s) => $s->toArray(), $this->repo->findByDepartmentId($departmentId));
    }

    /**
     * البحث في أسماء الخدمات ووصفها.
     * يُستخدم من ChatController.
     *
     * @param  string $keyword
     * @return array[]
     */
    public function search(string $keyword): array
    {
        return array_map(fn($s) => $s->toArray(), $this->repo->searchByNameOrDescription($keyword));
    }
}
