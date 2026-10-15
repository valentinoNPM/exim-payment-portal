<?php

namespace App\Support\CheckerFields;

use App\Models\Invoice;

final class GeneralCheckerFields
{
    public function canEditItemVat(Invoice $invoice): bool
    {
        return false;
    }
}
