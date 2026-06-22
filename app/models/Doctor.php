<?php

/**
 * Doctor.php
 * ----------
 * يمثّل سجل طبيب واحد من جدول Doctor.
 */

class Doctor
{
    public int     $doctor_id;
    public string  $full_name;
    public string  $specialty;
    public ?string $phone;
    public ?string $email;
    public ?string $photo_url;
    public int     $department_id;

    /**
     * حقول اختيارية تُملأ عند JOIN مع جدول Department.
     * لا توجد في جدول Doctor مباشرةً.
     */
    public ?string $department_name  = null;

    public function __construct(array $row)
    {
        $this->doctor_id      = (int)    $row['doctor_id'];
        $this->full_name      = (string) $row['full_name'];
        $this->specialty      = (string) $row['specialty'];
        $this->phone          = isset($row['phone'])     ? (string) $row['phone']     : null;
        $this->email          = isset($row['email'])     ? (string) $row['email']     : null;
        $this->photo_url      = isset($row['photo_url']) ? (string) $row['photo_url'] : null;
        $this->department_id  = (int)    $row['department_id'];

        // يُعبأ عند الاستعلام مع JOIN
        if (isset($row['department_name'])) {
            $this->department_name = (string) $row['department_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'doctor_id'     => $this->doctor_id,
            'full_name'     => $this->full_name,
            'specialty'     => $this->specialty,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'photo_url'     => $this->photo_url,
            'department_id' => $this->department_id,
        ];

        if ($this->department_name !== null) {
            $data['department_name'] = $this->department_name;
        }

        return $data;
    }
}
