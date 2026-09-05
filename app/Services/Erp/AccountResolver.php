<?php

namespace App\Services\Erp;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Validation\ValidationException;

class AccountResolver
{
    public function resolve(InvoiceItem $item, Invoice $invoice): string
    {
        foreach ([$item->coa_code_snapshot, $item->chartOfAccount?->code] as $code) {
            if (filled($code)) {
                return trim((string) $code);
            }
        }
        throw ValidationException::withMessages(['erp' => "Account is missing for invoice {$invoice->invoice_number}, item \"{$item->item_name}\". Assign a COA during Accounting review before exporting."]);
    }
}
