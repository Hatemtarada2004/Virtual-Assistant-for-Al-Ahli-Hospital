<?php

/**
 * InvoiceItem.php
 * ---------------
 * يمثّل بند واحد داخل فاتورة من جدول InvoiceItem.
 * يرتبط دائماً بـ Invoice عبر invoice_id.
 */

class InvoiceItem
{
    public int    $invoice_item_id;
    public int    $invoice_id;
    public int    $service_id;
    public int    $qty;
    public float  $unit_price;
    public float  $line_total;

    /** يُملأ عند JOIN مع Service */
    public ?string $service_name = null;

    public function __construct(array $row)
    {
        $this->invoice_item_id = (int)   $row['invoice_item_id'];
        $this->invoice_id      = (int)   $row['invoice_id'];
        $this->service_id      = (int)   $row['service_id'];
        $this->qty             = (int)   $row['qty'];
        $this->unit_price      = (float) $row['unit_price'];
        $this->line_total      = (float) $row['line_total'];

        if (isset($row['service_name'])) {
            $this->service_name = (string) $row['service_name'];
        }
    }

    public function toArray(): array
    {
        $data = [
            'invoice_item_id' => $this->invoice_item_id,
            'invoice_id'      => $this->invoice_id,
            'service_id'      => $this->service_id,
            'qty'             => $this->qty,
            'unit_price'      => $this->unit_price,
            'line_total'      => $this->line_total,
        ];

        if ($this->service_name !== null) {
            $data['service_name'] = $this->service_name;
        }

        return $data;
    }
}
