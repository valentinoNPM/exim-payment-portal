<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateGeneralPaymentSlip extends CreatePaymentSlip
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_GENERAL;
}
