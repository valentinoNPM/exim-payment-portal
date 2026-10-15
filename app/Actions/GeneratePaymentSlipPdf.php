<?php

namespace App\Actions;

use App\Models\PaymentSlip;
use Barryvdh\DomPDF\Facade\Pdf;

class GeneratePaymentSlipPdf
{
    public function execute(PaymentSlip $slip): \Barryvdh\DomPDF\PDF
    {
        // Eager load relations for PDF content
        $slip->load(['supplier', 'customer', 'buyer', 'invoices.buyer', 'invoices.documentFile', 'invoices.items.unit', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $view = $slip->transaction_type === PaymentSlip::TYPE_GENERAL
            ? 'pdf.payment-slips.general'
            : 'pdf.payment-slips.exim';

        // F4 Indonesia: 210 x 330 mm, converted to PDF points.
        return Pdf::loadView($view, [
            'slip' => $slip,
        ])->setPaper([0, 0, 595.28, 935.43], 'portrait');
    }
}
