<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateExportPaymentSlipEditor extends CreatePaymentSlipEditor
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_EXPORT;
}
