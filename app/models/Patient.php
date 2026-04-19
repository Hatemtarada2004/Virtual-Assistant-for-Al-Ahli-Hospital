<?php

class Patient
{
    public int $patient_id;
    public string $full_name;
    public ?string $national_id;
    public string $phone;
    public ?string $email;
    public ?string $date_of_birth;
    public ?string $gender;
    public string $created_at;

    public function __construct(array $row)
    {
        $this->patient_id    = (int) $row['patient_id'];
        $this->full_name     = (string) $row['full_name'];
        $this->national_id   = isset($row['national_id']) && $row['national_id'] !== ''
            ? (string) $row['national_id']
            : null;
        $this->phone         = (string) $row['phone'];
        $this->email         = isset($row['email']) && $row['email'] !== ''
            ? (string) $row['email']
            : null;
        $this->date_of_birth = isset($row['date_of_birth']) && $row['date_of_birth'] !== ''
            ? (string) $row['date_of_birth']
            : null;
        $this->gender        = isset($row['gender']) && $row['gender'] !== ''
            ? (string) $row['gender']
            : null;
        $this->created_at    = (string) ($row['created_at'] ?? '');
    }

    public function toArray(): array
    {
        return [
            'patient_id'     => $this->patient_id,
            'full_name'      => $this->full_name,
            'national_id'    => $this->national_id,
            'phone'          => $this->phone,
            'email'          => $this->email,
            'date_of_birth'  => $this->date_of_birth,
            'gender'         => $this->gender,
            'created_at'     => $this->created_at,
        ];
    }
}
