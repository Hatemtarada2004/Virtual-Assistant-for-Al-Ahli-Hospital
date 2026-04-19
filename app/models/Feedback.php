<?php

/**
 * Feedback.php
 * ------------
 * يمثّل سجل ملاحظة أو شكوى واحدة من جدول Feedback.
 *
 * القيم المسموحة لـ type:   Feedback | Complaint
 * القيم المسموحة لـ status: New | InReview | Closed
 */

class Feedback
{
    public const TYPE_FEEDBACK  = 'Feedback';
    public const TYPE_COMPLAINT = 'Complaint';

    public const STATUS_NEW       = 'New';
    public const STATUS_IN_REVIEW = 'InReview';
    public const STATUS_CLOSED    = 'Closed';

    public int     $feedback_id;
    public ?int    $patient_id;   // يمكن أن يكون null (زائر مجهول)
    public string  $type;
    public string  $message;
    public string  $status;
    public string  $created_at;

    /** يُملأ عند JOIN مع Patient */
    public ?string $patient_name = null;

    public function __construct(array $row)
    {
        $this->feedback_id  = (int)    $row['feedback_id'];
        $this->patient_id   = isset($row['patient_id']) && $row['patient_id'] !== null
                                ? (int) $row['patient_id']
                                : null;
        $this->type         = (string) $row['type'];
        $this->message      = (string) $row['message'];
        $this->status       = (string) $row['status'];
        $this->created_at   = (string) ($row['created_at'] ?? '');

        if (isset($row['patient_name'])) {
            $this->patient_name = (string) $row['patient_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'feedback_id' => $this->feedback_id,
            'patient_id'  => $this->patient_id,
            'type'        => $this->type,
            'message'     => $this->message,
            'status'      => $this->status,
            'created_at'  => $this->created_at,
        ];

        if ($this->patient_name !== null) {
            $data['patient_name'] = $this->patient_name;
        }

        return $data;
    }

    public static function allowedTypes(): array
    {
        return [self::TYPE_FEEDBACK, self::TYPE_COMPLAINT];
    }

    public static function allowedStatuses(): array
    {
        return [self::STATUS_NEW, self::STATUS_IN_REVIEW, self::STATUS_CLOSED];
    }
}
