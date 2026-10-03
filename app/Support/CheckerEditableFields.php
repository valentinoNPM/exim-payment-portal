<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\PaymentSlip;

class CheckerEditableFields
{
    public static function canEditInvoiceVat(PaymentSlip $slip): bool
    {
        return self::isSubmittedChecker($slip);
    }

    public static function canEditItemVat(PaymentSlip $slip, Invoice $invoice): bool
    {
        if (! self::isSubmittedChecker($slip)) {
            return false;
        }

        return $slip->transaction_type === PaymentSlip::TYPE_IMPORT
            || ($slip->transaction_type === PaymentSlip::TYPE_EXPORT && $invoice->usesItemizedTaxes());
    }

    public static function canEditItemCoa(PaymentSlip $slip): bool
    {
        return self::isSubmittedChecker($slip);
    }

    private static function isSubmittedChecker(PaymentSlip $slip): bool
    {
        return $slip->status === 'submitted'
            && (auth()->user()?->hasRole('checker') ?? false);
    }
}
