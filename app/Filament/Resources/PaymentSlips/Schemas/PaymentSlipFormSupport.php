<?php

namespace App\Filament\Resources\PaymentSlips\Schemas;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Models\Tax;
use App\Services\InvoiceAmountCalculator;
use App\Services\PaymentSlips\InvoiceDraftCalculator;
use App\Support\CurrencyFormatter;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

abstract class PaymentSlipFormSupport
{
    abstract protected static function isItemizedMode(Get $get): bool;

    /** @return array<int, string> */
    protected static function selectedDocumentFileOption(Select $component): array
    {
        $documentFileId = $component->getState();

        if (blank($documentFileId)) {
            return [];
        }

        return DocumentFile::query()
            ->whereKey($documentFileId)
            ->pluck('original_name', 'id')
            ->all();
    }

    protected static function setCalculatedAmounts(Set $set, float $subtotal, mixed $ppnTaxId, mixed $pphTaxId, string $currency = PaymentSlip::CURRENCY_IDR): void
    {
        $amounts = InvoiceAmountCalculator::calculateForCurrency(
            $subtotal,
            Tax::find($ppnTaxId)?->rate,
            Tax::find($pphTaxId)?->rate,
            $currency,
        );

        $set('tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_addition'], $currency));
        $set('tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_deduction'], $currency));
        $set('grand_total_amount', CurrencyFormatter::formatFormStateNoDecimals($amounts['grand_total'], $currency));
    }

    protected static function recalculateInvoiceFromItem(Get $get, Set $set, ?string $changedTax = null): void
    {
        InvoiceDraftCalculator::recalculateFromItem(
            fn (string $path): mixed => $get($path),
            function (string $path, mixed $value) use ($set): void {
                $set($path, $value);
            },
            static::isItemizedMode($get),
            $changedTax,
        );
    }

    protected static function paymentSlipForActionRecord(?object $record): ?PaymentSlip
    {
        if ($record instanceof PaymentSlip) {
            return $record;
        }

        return $record instanceof Invoice ? $record->paymentSlip : null;
    }

    protected static function money(mixed $value): float
    {
        return InvoiceDraftCalculator::money($value);
    }
}
