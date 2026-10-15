<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Support\CheckerFields\EximCheckerFields;
use App\Support\CheckerFields\GeneralCheckerFields;

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

        return match ($slip->transaction_type) {
            PaymentSlip::TYPE_GENERAL => (new GeneralCheckerFields)->canEditItemVat($invoice),
            PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT => (new EximCheckerFields($slip))->canEditItemVat($invoice),
            default => false,
        };
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
