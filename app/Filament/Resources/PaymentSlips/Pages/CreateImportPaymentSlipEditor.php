<?php

namespace App\Filament\Resources\PaymentSlips\Pages;

use App\Models\PaymentSlip;

class CreateImportPaymentSlipEditor extends CreatePaymentSlipEditor
{
    protected ?string $fixedTransactionType = PaymentSlip::TYPE_IMPORT;
}
