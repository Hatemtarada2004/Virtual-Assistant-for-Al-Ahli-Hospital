<?php

/**
 * InsuranceProvider.php
 * ---------------------
 * يمثّل سجل شركة تأمين واحدة من جدول InsuranceProvider.
 */

class InsuranceProvider
{
    public int     $provider_id;
    public string  $name;
    public ?string $phone;

    public function __construct(array $row)
    {
        $this->provider_id = (int)    $row['provider_id'];
        $this->name        = (string) $row['name'];
        $this->phone       = isset($row['phone']) ? (string) $row['phone'] : null;
    }

    public function toArray(): array
    {
        return [
            'provider_id' => $this->provider_id,
            'name'        => $this->name,
            'phone'       => $this->phone,
        ];
    }
}
