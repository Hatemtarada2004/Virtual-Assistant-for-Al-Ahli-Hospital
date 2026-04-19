<?php

/**
 * Department.php
 * --------------
 * يمثّل سجل قسم طبي واحد من جدول Department.
 */

class Department
{
    public int     $department_id;
    public string  $name;
    public string  $location;
    public string  $working_hours;

    public function __construct(array $row)
    {
        $this->department_id  = (int)    $row['department_id'];
        $this->name           = (string) $row['name'];
        $this->location       = (string) $row['location'];
        $this->working_hours  = (string) $row['working_hours'];
    }

    /**
     * تحويل الكائن إلى مصفوفة جاهزة للإرسال كـ JSON.
     */
    public function toArray(): array
    {
        return [
            'department_id' => $this->department_id,
            'name'          => $this->name,
            'location'      => $this->location,
            'working_hours' => $this->working_hours,
        ];
    }
}
