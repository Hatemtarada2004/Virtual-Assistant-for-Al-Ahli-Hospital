<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Invoice.php';
require_once __DIR__ . '/../models/InvoiceItem.php';

/**
 * InvoiceRepository
 * -----------------
 * استعلامات جدولي Invoice و InvoiceItem.
 * يُعيد كائنات Invoice محملة ببنودها (items) دائماً.
 */
class InvoiceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // -------------------------------------------------------
    // READ
    // -------------------------------------------------------

    /**
     * جلب فاتورة واحدة بمعرّفها مع بنودها.
     *
     * @param  int $id
     * @return Invoice|null
     */
    public function findById(int $id): ?Invoice
    {
        $stmt = $this->pdo->prepare('
            SELECT inv.invoice_id,
                   inv.patient_id,
                   inv.issue_date,
                   inv.total_amount,
                   inv.status,
                   p.full_name AS patient_name
            FROM   Invoice inv
            JOIN   Patient  p ON p.patient_id = inv.patient_id
            WHERE  inv.invoice_id = :id
            LIMIT  1
        ');
        $stmt->execute([':id' => $id]);

        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $invoice        = new Invoice($row);
        $invoice->items = $this->fetchItemsForInvoice($invoice->invoice_id);
        return $invoice;
    }

    /**
     * جلب جميع فواتير مريض معين مع بنود كل فاتورة، مرتبة من الأحدث.
     *
     * @param  int $patientId
     * @return Invoice[]
     */
    public function findByPatientId(int $patientId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT inv.invoice_id,
                   inv.patient_id,
                   inv.issue_date,
                   inv.total_amount,
                   inv.status,
                   p.full_name AS patient_name
            FROM   Invoice inv
            JOIN   Patient  p ON p.patient_id = inv.patient_id
            WHERE  inv.patient_id = :patient_id
            ORDER  BY inv.issue_date DESC
        ');
        $stmt->execute([':patient_id' => $patientId]);

        $rows = $stmt->fetchAll();

        $invoices = [];
        foreach ($rows as $row) {
            $invoice        = new Invoice($row);
            $invoice->items = $this->fetchItemsForInvoice($invoice->invoice_id);
            $invoices[]     = $invoice;
        }

        return $invoices;
    }

    // -------------------------------------------------------
    // PRIVATE — بنود الفاتورة
    // -------------------------------------------------------

    /**
     * جلب بنود فاتورة واحدة مع اسم الخدمة.
     *
     * @param  int $invoiceId
     * @return InvoiceItem[]
     */
    private function fetchItemsForInvoice(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ii.invoice_item_id,
                   ii.invoice_id,
                   ii.service_id,
                   ii.qty,
                   ii.unit_price,
                   ii.line_total,
                   s.name AS service_name
            FROM   InvoiceItem ii
            JOIN   Service s ON s.service_id = ii.service_id
            WHERE  ii.invoice_id = :invoice_id
            ORDER  BY ii.invoice_item_id ASC
        ');
        $stmt->execute([':invoice_id' => $invoiceId]);

        return array_map(fn(array $row) => new InvoiceItem($row), $stmt->fetchAll());
    }
}
