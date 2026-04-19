<?php

/**
 * InsurancePolicy.php
 * -------------------
 * يمثّل سجل بوليصة تأمين واحدة من جدول InsurancePolicy.
 * كل بوليصة مرتبطة بمريض واحد وشركة تأمين واحدة.
 */

class InsurancePolicy
{
    public int     $policy_id;
    public int     $patient_id;
    public int     $provider_id;
    public string  $policy_number;
    public ?string $coverage_details;
    public ?string $valid_from;    // YYYY-MM-DD
    public ?string $valid_to;      // YYYY-MM-DD

    /** حقول اختيارية تُملأ عند JOIN */
    public ?string $patient_name   = null;
    public ?string $provider_name  = null;
    public ?string $provider_phone = null;

    public function __construct(array $row)
    {
        $this->policy_id         = (int)    $row['policy_id'];
        $this->patient_id        = (int)    $row['patient_id'];
        $this->provider_id       = (int)    $row['provider_id'];
        $this->policy_number     = (string) $row['policy_number'];
        $this->coverage_details  = isset($row['coverage_details']) ? (string) $row['coverage_details'] : null;
        $this->valid_from        = isset($row['valid_from'])        ? (string) $row['valid_from']        : null;
        $this->valid_to          = isset($row['valid_to'])          ? (string) $row['valid_to']          : null;

        if (isset($row['patient_name']))   $this->patient_name   = (string) $row['patient_name'];
        if (isset($row['provider_name']))  $this->provider_name  = (string) $row['provider_name'];
        if (isset($row['provider_phone'])) $this->provider_phone = (string) $row['provider_phone'];
    }

    public function toArray(): array
    {
        $data = [
            'policy_id'        => $this->policy_id,
            'patient_id'       => $this->patient_id,
            'provider_id'      => $this->provider_id,
            'policy_number'    => $this->policy_number,
            'coverage_details' => $this->coverage_details,
            'valid_from'       => $this->valid_from,
            'valid_to'         => $this->valid_to,
        ];

        if ($this->patient_name   !== null) $data['patient_name']   = $this->patient_name;
        if ($this->provider_name  !== null) $data['provider_name']  = $this->provider_name;
        if ($this->provider_phone !== null) $data['provider_phone'] = $this->provider_phone;

        return $data;
    }
}
