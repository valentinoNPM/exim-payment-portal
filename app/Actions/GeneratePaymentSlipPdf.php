<?php

namespace App\Actions;

use App\Models\PaymentSlip;
use Barryvdh\DomPDF\Facade\Pdf;

class GeneratePaymentSlipPdf
{
    public function execute(PaymentSlip $slip): \Barryvdh\DomPDF\PDF
    {
        // Eager load relations for PDF content
        $slip->load(['supplier', 'buyer', 'invoices.documentFile', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        return Pdf::loadView('pdf.payment-slip', [
            'slip' => $slip,
        ])->setPaper('a4', 'portrait');
    }
}
