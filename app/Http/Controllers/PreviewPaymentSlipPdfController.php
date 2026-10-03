<?php

namespace App\Http\Controllers;

use App\Actions\GeneratePaymentSlipPdf;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use Symfony\Component\HttpFoundation\Response;

class PreviewPaymentSlipPdfController extends Controller
{
    public function __invoke(PaymentSlip $paymentSlip, GeneratePaymentSlipPdf $generatePaymentSlipPdf): Response
    {
        abort_unless(PaymentSlipResource::canView($paymentSlip), 403);

        return $generatePaymentSlipPdf
            ->execute($paymentSlip)
            ->stream("payment-slip-{$paymentSlip->slip_number}.pdf");
    }
}
