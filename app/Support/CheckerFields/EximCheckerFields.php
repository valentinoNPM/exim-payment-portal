<?php

namespace App\Support\CheckerFields;

use App\Models\Invoice;
use App\Models\PaymentSlip;

final class EximCheckerFields
{
    public function __construct(private readonly PaymentSlip $slip) {}

    public function canEditItemVat(Invoice $invoice): bool
    {
        return $this->slip->transaction_type === PaymentSlip::TYPE_IMPORT
            || ($this->slip->transaction_type === PaymentSlip::TYPE_EXPORT && $invoice->usesItemizedTaxes());
    }
}
