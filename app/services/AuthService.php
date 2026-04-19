<?php

require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';

class AuthService
{
    private PatientRepository $patients;

    public function __construct()
    {
        $this->patients = new PatientRepository();
    }

    public function register(array $data): array
    {
        $errors = Validator::validate($data, [
            'full_name' => ['required', 'string', 'minLength:3', 'maxLength:150'],
            'national_id' => ['required', 'string', 'minLength:6', 'maxLength:30'],
            'phone' => ['required', 'phone', 'maxLength:30'],
            'email' => ['email', 'maxLength:150'],
            'date_of_birth' => ['date'],
            'gender' => ['enum:Male,Female'],
        ]);

        if (!empty($errors)) {
            throw new InvalidArgumentException(json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        $fullName = trim((string) $data['full_name']);
        $nationalId = trim((string) $data['national_id']);
        $phone = $this->normalizePhone((string) $data['phone']);
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null;
        $dateOfBirth = isset($data['date_of_birth']) && trim((string) $data['date_of_birth']) !== '' ? trim((string) $data['date_of_birth']) : null;
        $gender = isset($data['gender']) && trim((string) $data['gender']) !== '' ? trim((string) $data['gender']) : null;

        if ($this->patients->nationalIdExists($nationalId)) {
            throw new InvalidArgumentException('الرقم الوطني مستخدم بالفعل.');
        }

        if ($this->patients->phoneExists($phone)) {
            throw new InvalidArgumentException('رقم الهاتف مستخدم بالفعل.');
        }

        $patientId = $this->patients->create(
            $fullName,
            $nationalId,
            $phone,
            $email,
            $dateOfBirth,
            $gender
        );

        return $this->patients->findById($patientId)?->toArray()
            ?? throw new RuntimeException('فشل استرجاع ملف المريض بعد الإنشاء.');
    }

    public function login(array $data): array
    {
        $errors = Validator::validate($data, [
            'national_id' => ['required', 'string', 'minLength:6', 'maxLength:30'],
            'phone' => ['required', 'phone', 'maxLength:30'],
        ]);

        if (!empty($errors)) {
            throw new InvalidArgumentException('الرقم الوطني ورقم الهاتف مطلوبان بصيغة صحيحة.');
        }

        $nationalId = trim((string) $data['national_id']);
        $phone = $this->normalizePhone((string) $data['phone']);

        $patient = $this->patients->findByNationalIdAndPhone($nationalId, $phone);
        if ($patient === null) {
            throw new InvalidArgumentException('تعذر العثور على ملف مريض مطابق للرقم الوطني ورقم الهاتف.');
        }

        return $patient->toArray();
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', trim($phone)) ?? trim($phone);
    }
}
