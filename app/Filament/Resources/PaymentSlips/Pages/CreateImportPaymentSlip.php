<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateImportPaymentSlip extends CreatePaymentSlip
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_IMPORT;
}
