<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateExportPaymentSlip extends CreatePaymentSlip
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_EXPORT;
}
