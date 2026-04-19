<?php

class Reminder
{
    public int $reminder_id;
    public int $patient_id;
    public string $reminder_type;
    public string $message;
    public string $remind_at;
    public bool $sent_flag;
    public ?string $patient_name = null;

    public function __construct(array $row)
    {
        $this->reminder_id = (int) $row['reminder_id'];
        $this->patient_id = (int) $row['patient_id'];
        $this->reminder_type = (string) $row['reminder_type'];
        $this->message = (string) $row['message'];
        $this->remind_at = (string) $row['remind_at'];
        $this->sent_flag = (bool) $row['sent_flag'];

        if (isset($row['patient_name'])) {
            $this->patient_name = (string) $row['patient_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'reminder_id' => $this->reminder_id,
            'patient_id' => $this->patient_id,
            'reminder_type' => $this->reminder_type,
            'message' => $this->message,
            'remind_at' => $this->remind_at,
            'sent_flag' => $this->sent_flag,
        ];

        if ($this->patient_name !== null) {
            $data['patient_name'] = $this->patient_name;
        }

        return $data;
    }
}
