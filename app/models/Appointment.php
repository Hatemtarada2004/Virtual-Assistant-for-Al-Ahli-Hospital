<?php

class Appointment
{
    public const STATUS_BOOKED = 'Booked';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_NO_SHOW = 'NoShow';

    public int $appointment_id;
    public int $patient_id;
    public int $doctor_id;
    public int $department_id;
    public string $appointment_datetime;
    public string $status;
    public ?string $reason;
    public string $created_at;
    public ?string $patient_name = null;
    public ?string $doctor_name = null;
    public ?string $department_name = null;
    public array $services = [];

    public function __construct(array $row)
    {
        $this->appointment_id = (int) $row['appointment_id'];
        $this->patient_id = (int) $row['patient_id'];
        $this->doctor_id = (int) $row['doctor_id'];
        $this->department_id = (int) $row['department_id'];
        $this->appointment_datetime = (string) $row['appointment_datetime'];
        $this->status = (string) $row['status'];
        $this->reason = isset($row['reason']) ? (string) $row['reason'] : null;
        $this->created_at = (string) ($row['created_at'] ?? '');

        if (isset($row['patient_name'])) {
            $this->patient_name = (string) $row['patient_name'];
        }
        if (isset($row['doctor_name'])) {
            $this->doctor_name = (string) $row['doctor_name'];
        }
        if (isset($row['department_name'])) {
            $this->department_name = (string) $row['department_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'appointment_id' => $this->appointment_id,
            'patient_id' => $this->patient_id,
            'doctor_id' => $this->doctor_id,
            'department_id' => $this->department_id,
            'appointment_datetime' => $this->appointment_datetime,
            'status' => $this->status,
            'reason' => $this->reason,
            'created_at' => $this->created_at,
        ];

        if ($this->patient_name !== null) {
            $data['patient_name'] = $this->patient_name;
        }
        if ($this->doctor_name !== null) {
            $data['doctor_name'] = $this->doctor_name;
        }
        if ($this->department_name !== null) {
            $data['department_name'] = $this->department_name;
        }
        if (!empty($this->services)) {
            $data['services'] = $this->services;
        }

        return $data;
    }

    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_BOOKED,
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
            self::STATUS_NO_SHOW,
        ];
    }
}
