<?php

require_once __DIR__ . '/../repositories/InvoiceRepository.php';
require_once __DIR__ . '/../repositories/PatientRepository.php';
require_once __DIR__ . '/../helpers/Validator.php';

/**
 * InvoiceService
 * --------------
 * Business logic لعمليات الفواتير.
 */
class InvoiceService
{
    private InvoiceRepository $invoiceRepo;
    private PatientRepository $patientRepo;

    public function __construct()
    {
        $this->invoiceRepo = new InvoiceRepository();
        $this->patientRepo = new PatientRepository();
    }

    /**
     * جلب جميع فواتير مريض معين مع بنودها.
     *
     * @param  int $patientId
     * @return array[]
     * @throws InvalidArgumentException
     */
    public function getByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $invoices = $this->invoiceRepo->findByPatientId($patientId);
        return array_map(fn($inv) => $inv->toArray(), $invoices);
    }

    /**
     * جلب فاتورة واحدة بمعرّفها مع بنودها.
     *
     * @param  int $invoiceId
     * @return array|null
     */
    public function getById(int $invoiceId): ?array
    {
        return $this->invoiceRepo->findById($invoiceId)?->toArray();
    }

    /**
     * ملخص مالي لمريض: إجمالي مدفوع، غير مدفوع، جزئي.
     * يُستخدم من ChatController لتزويد الـ prompt ببيانات مختصرة.
     *
     * @param  int $patientId
     * @return array ['total_unpaid' => float, 'total_paid' => float, 'invoices_count' => int, ...]
     * @throws InvalidArgumentException
     */
    public function getSummaryByPatient(int $patientId): array
    {
        $this->assertPatientExists($patientId);

        $invoices    = $this->invoiceRepo->findByPatientId($patientId);
        $totalUnpaid = 0.0;
        $totalPaid   = 0.0;
        $totalPartial = 0.0;

        foreach ($invoices as $inv) {
            match ($inv->status) {
                'Unpaid'        => $totalUnpaid   += $inv->total_amount,
                'Paid'          => $totalPaid      += $inv->total_amount,
                'PartiallyPaid' => $totalPartial   += $inv->total_amount,
                default         => null,
            };
        }

        return [
            'invoices_count'   => count($invoices),
            'total_paid'       => round($totalPaid, 2),
            'total_unpaid'     => round($totalUnpaid, 2),
            'total_partial'    => round($totalPartial, 2),
            'invoices'         => array_map(fn($inv) => $inv->toArray(), $invoices),
        ];
    }

    // -------------------------------------------------------
    // Private
    // -------------------------------------------------------

    private function assertPatientExists(int $patientId): void
    {
        if (!$this->patientRepo->existsById($patientId)) {
            throw new InvalidArgumentException("المريض بالمعرّف {$patientId} غير موجود.");
        }
    }
}
