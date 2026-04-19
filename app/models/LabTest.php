<?php

/**
 * LabTest.php
 * -----------
 * يمثّل سجل فحص مخبري واحد من جدول LabTest.
 *
 * القيم المسموحة لـ status: Pending | Ready
 */

class LabTest
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_READY   = 'Ready';

    public int     $lab_test_id;
    public int     $patient_id;
    public ?int    $ordered_by_doctor_id;
    public string  $test_name;
    public string  $test_date;     // YYYY-MM-DD HH:MM:SS
    public ?string $result_text;
    public string  $status;

    /** حقول اختيارية تُملأ عند JOIN */
    public ?string $patient_name = null;
    public ?string $doctor_name  = null;

    public function __construct(array $row)
    {
        $this->lab_test_id           = (int)    $row['lab_test_id'];
        $this->patient_id            = (int)    $row['patient_id'];
        $this->ordered_by_doctor_id  = isset($row['ordered_by_doctor_id']) && $row['ordered_by_doctor_id'] !== null
                                         ? (int) $row['ordered_by_doctor_id']
                                         : null;
        $this->test_name             = (string) $row['test_name'];
        $this->test_date             = (string) $row['test_date'];
        $this->result_text           = isset($row['result_text']) ? (string) $row['result_text'] : null;
        $this->status                = (string) $row['status'];

        if (isset($row['patient_name'])) $this->patient_name = (string) $row['patient_name'];
        if (isset($row['doctor_name']))  $this->doctor_name  = (string) $row['doctor_name'];
    }

    public function toArray(): array
    {
        $data = [
            'lab_test_id'          => $this->lab_test_id,
            'patient_id'           => $this->patient_id,
            'ordered_by_doctor_id' => $this->ordered_by_doctor_id,
            'test_name'            => $this->test_name,
            'test_date'            => $this->test_date,
            'result_text'          => $this->result_text,
            'status'               => $this->status,
        ];

        if ($this->patient_name !== null) $data['patient_name'] = $this->patient_name;
        if ($this->doctor_name  !== null) $data['doctor_name']  = $this->doctor_name;

        return $data;
    }

    public static function allowedStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_READY];
    }
}
