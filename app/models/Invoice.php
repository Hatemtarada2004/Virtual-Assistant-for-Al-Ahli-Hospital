<?php

/**
 * Invoice.php
 * -----------
 * يمثّل سجل فاتورة واحدة من جدول Invoice.
 *
 * القيم المسموحة لـ status: Unpaid | Paid | PartiallyPaid
 */

class Invoice
{
    public const STATUS_UNPAID         = 'Unpaid';
    public const STATUS_PAID           = 'Paid';
    public const STATUS_PARTIALLY_PAID = 'PartiallyPaid';

    public int     $invoice_id;
    public int     $patient_id;
    public string  $issue_date;      // YYYY-MM-DD
    public float   $total_amount;
    public string  $status;

    /** يُملأ عند JOIN مع Patient */
    public ?string $patient_name = null;

    /**
     * بنود الفاتورة — تُملأ يدوياً في الـ Repository
     * بعد استعلام منفصل على جدول InvoiceItem.
     *
     * @var InvoiceItem[]
     */
    public array $items = [];

    public function __construct(array $row)
    {
        $this->invoice_id    = (int)    $row['invoice_id'];
        $this->patient_id    = (int)    $row['patient_id'];
        $this->issue_date    = (string) $row['issue_date'];
        $this->total_amount  = (float)  $row['total_amount'];
        $this->status        = (string) $row['status'];

        if (isset($row['patient_name'])) {
            $this->patient_name = (string) $row['patient_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'invoice_id'   => $this->invoice_id,
            'patient_id'   => $this->patient_id,
            'issue_date'   => $this->issue_date,
            'total_amount' => $this->total_amount,
            'status'       => $this->status,
            'items'        => array_map(fn(InvoiceItem $i) => $i->toArray(), $this->items),
        ];

        if ($this->patient_name !== null) {
            $data['patient_name'] = $this->patient_name;
        }

        return $data;
    }

    public static function allowedStatuses(): array
    {
        return [self::STATUS_UNPAID, self::STATUS_PAID, self::STATUS_PARTIALLY_PAID];
    }
}
