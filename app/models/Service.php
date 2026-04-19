<?php

/**
 * Service.php
 * -----------
 * يمثّل سجل خدمة طبية واحدة من جدول Service.
 */

class Service
{
    public int     $service_id;
    public string  $name;
    public ?string $description;
    public float   $base_cost;
    public int     $department_id;

    /** يُملأ عند JOIN مع Department */
    public ?string $department_name = null;

    public function __construct(array $row)
    {
        $this->service_id     = (int)   $row['service_id'];
        $this->name           = (string) $row['name'];
        $this->description    = isset($row['description']) ? (string) $row['description'] : null;
        $this->base_cost      = (float)  $row['base_cost'];
        $this->department_id  = (int)    $row['department_id'];

        if (isset($row['department_name'])) {
            $this->department_name = (string) $row['department_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'service_id'    => $this->service_id,
            'name'          => $this->name,
            'description'   => $this->description,
            'base_cost'     => $this->base_cost,
            'department_id' => $this->department_id,
        ];

        if ($this->department_name !== null) {
            $data['department_name'] = $this->department_name;
        }

        return $data;
    }
}
