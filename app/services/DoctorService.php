<?php

require_once __DIR__ . '/../repositories/DoctorRepository.php';

/**
 * DoctorService
 * -------------
 * Business logic لعمليات الأطباء.
 */
class DoctorService
{
    private DoctorRepository $repo;

    public function __construct()
    {
        $this->repo = new DoctorRepository();
    }

    /**
     * جلب جميع الأطباء.
     *
     * @return array[]
     */
    public function getAll(): array
    {
        return array_map(fn($d) => $d->toArray(), $this->repo->findAll());
    }

    /**
     * جلب طبيب واحد بمعرّفه.
     *
     * @param  int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        return $this->repo->findById($id)?->toArray();
    }

    /**
     * جلب أطباء قسم معين.
     *
     * @param  int $departmentId
     * @return array[]
     */
    public function getByDepartment(int $departmentId): array
    {
        return array_map(fn($d) => $d->toArray(), $this->repo->findByDepartmentId($departmentId));
    }

    /**
     * جلب أطباء حسب التخصص.
     *
     * @param  string $specialty
     * @return array[]
     */
    public function getBySpecialty(string $specialty): array
    {
        return array_map(fn($d) => $d->toArray(), $this->repo->findBySpecialty($specialty));
    }

    /**
     * البحث في الأسماء والتخصصات بكلمة مفتاحية.
     * يُستخدم من ChatController.
     *
     * @param  string $keyword
     * @return array[]
     */
    public function search(string $keyword): array
    {
        return array_map(fn($d) => $d->toArray(), $this->repo->searchByNameOrSpecialty($keyword));
    }

    /**
     * التحقق من وجود طبيب بمعرّف معين.
     *
     * @param  int $id
     * @return bool
     */
    public function exists(int $id): bool
    {
        return $this->repo->existsById($id);
    }
}
