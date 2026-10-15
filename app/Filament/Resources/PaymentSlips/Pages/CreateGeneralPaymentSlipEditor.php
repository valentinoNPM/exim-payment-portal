<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateGeneralPaymentSlipEditor extends CreatePaymentSlipEditor
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_GENERAL;
}
