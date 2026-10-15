<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use App\Models\Tax;
use App\Services\InvoiceAmountCalculator;
use App\Support\CurrencyFormatter;
use App\Support\MoneyRounding;

class InvoiceDraftCalculator
{
    /**
     * @param  callable(string): mixed  $get
     * @param  callable(string, mixed): void  $set
     */
    public static function recalculateFromItem(callable $get, callable $set, bool $itemized, ?string $changedTax = null): void
    {
        $subtotal = self::money($get('quantity')) * self::money($get('unit_price_amount'));

        // Compute fresh tax amounts for the *current* item so we can use them
        // both for setting the item fields and for the net_amount calculation.
        $currentPpnAmount = 0.0;
        $currentPphAmount = 0.0;
        $currency = $get('../../../../currency') ?? 'IDR';
        $general = $get('../../../../transaction_type') === PaymentSlip::TYPE_GENERAL;
        if ($general) {
            $subtotal = MoneyRounding::round($subtotal, $currency);
        }

        if ($itemized) {
            $taxAmounts = InvoiceAmountCalculator::calculateForCurrency(
                $subtotal,
                Tax::find($get('ppn_tax_id'))?->rate,
                Tax::find($get('pph_tax_id'))?->rate,
                $currency,
            );

            $currentPpnAmount = (filled($get('ppn_tax_id')) || $changedTax === 'ppn')
                ? $taxAmounts['tax_addition']
                : self::money($get('tax_addition_amount'));
            $currentPphAmount = (filled($get('pph_tax_id')) || $changedTax === 'pph')
                ? $taxAmounts['tax_deduction']
                : self::money($get('tax_deduction_amount'));

            if (filled($get('ppn_tax_id')) || $changedTax === 'ppn') {
                $set('tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($taxAmounts['tax_addition'], $currency));
            }
            if (filled($get('pph_tax_id')) || $changedTax === 'pph') {
                $set('tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($taxAmounts['tax_deduction'], $currency));
            }
        }

        $net = $itemized
            ? $subtotal + $currentPpnAmount - $currentPphAmount
            : $subtotal;
        $set('net_amount', CurrencyFormatter::formatFormState($net, $currency));

        // Aggregate invoice totals from all items.  Instead of reading
        // tax_addition_amount / tax_deduction_amount hidden fields (which may
        // still hold stale values because $set() is deferred), recalculate
        // each item's taxes from its ppn_tax_id / pph_tax_id on-the-fly.
        $taxCache = [];
        $invoiceSubtotal = 0.0;
        $invoicePpn = 0.0;
        $invoicePph = 0.0;
        foreach (($get('../../items') ?? []) as $item) {
            $itemSubtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            if ($general) {
                $itemSubtotal = MoneyRounding::round($itemSubtotal, $currency);
            }
            $invoiceSubtotal += $itemSubtotal;
            if ($itemized) {
                $ppnId = $item['ppn_tax_id'] ?? null;
                $pphId = $item['pph_tax_id'] ?? null;
                $ppnRate = null;
                $pphRate = null;
                if (filled($ppnId)) {
                    $ppnRate = $taxCache[$ppnId] ??= Tax::find($ppnId)?->rate;
                }
                if (filled($pphId)) {
                    $pphRate = $taxCache[$pphId] ??= Tax::find($pphId)?->rate;
                }
                $itemTax = InvoiceAmountCalculator::calculateForCurrency($itemSubtotal, $ppnRate, $pphRate, $currency);
                $invoicePpn += $itemTax['tax_addition'];
                $invoicePph += $itemTax['tax_deduction'];
            }
        }

        if (! $itemized) {
            $amounts = InvoiceAmountCalculator::calculateForCurrency(
                $invoiceSubtotal,
                Tax::find($get('../../ppn_tax_id'))?->rate,
                Tax::find($get('../../pph_tax_id'))?->rate,
                $currency,
            );
            $invoicePpn = $amounts['tax_addition'];
            $invoicePph = $amounts['tax_deduction'];
        }

        $set('../../subtotal_amount', CurrencyFormatter::formatFormState($invoiceSubtotal, $currency));
        $set('../../tax_addition_amount', CurrencyFormatter::formatFormStateNoDecimals($invoicePpn, $currency));
        $set('../../tax_deduction_amount', CurrencyFormatter::formatFormStateNoDecimals($invoicePph, $currency));
        $set('../../grand_total_amount', CurrencyFormatter::formatFormStateNoDecimals($invoiceSubtotal + $invoicePpn - $invoicePph, $currency));
    }

    public static function recalculateLiveItemizedInvoice(array &$data, string $property): void
    {
        // Pattern 1: field-level update (e.g. data.invoices.{key}.items.{key}.ppn_tax_id)
        if (preg_match('/^data\.invoices\.([^.]+)\.items\.([^.]+)\.(quantity|unit_price_amount|ppn_tax_id|pph_tax_id)$/', $property, $matches)) {
            $changedField = $matches[3];
            // Pattern 2: item-level update without field suffix (e.g. data.invoices.{key}.items.{key})
            // Livewire sends this when a Select inside a programmatically populated
            // repeater (e.g. from Gemini extraction) is changed by the user.
        } elseif (preg_match('/^data\.invoices\.([^.]+)\.items\.([^.]+)$/', $property, $matches)) {
            $changedField = null;
        } else {
            return;
        }

        if (! in_array($data['transaction_type'] ?? null, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT, PaymentSlip::TYPE_GENERAL], true)) {
            return;
        }

        [$invoiceKey, $itemKey] = array_slice($matches, 1);
        if (! isset($data['invoices'][$invoiceKey]['items'][$itemKey])) {
            return;
        }

        $currency = $data['currency'] ?? 'IDR';

        $taxMode = $data['invoices'][$invoiceKey]['tax_calculation_mode'] ?? PaymentSlip::TAX_MODE_ITEMIZED;
        if (($data['transaction_type'] ?? null) !== PaymentSlip::TYPE_GENERAL && $taxMode === PaymentSlip::TAX_MODE_INVOICE_LEGACY) {
            self::recalculateInvoiceTaxState($data['invoices'][$invoiceKey], $currency);

            return;
        }

        self::recalculateItemizedInvoiceState($data['invoices'][$invoiceKey], $itemKey, $changedField, $currency, $data['transaction_type'] === PaymentSlip::TYPE_GENERAL);
    }

    /** @param array<string, mixed> $invoice */
    public static function recalculateInvoiceTaxState(array &$invoice, string $currency = 'IDR'): void
    {
        $subtotal = 0.0;
        foreach ($invoice['items'] ?? [] as &$item) {
            $itemSubtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            $item['net_amount'] = CurrencyFormatter::formatFormState($itemSubtotal, $currency);
            $subtotal += $itemSubtotal;
        }
        unset($item);

        $amounts = InvoiceAmountCalculator::calculateForCurrency(
            $subtotal,
            Tax::find($invoice['ppn_tax_id'] ?? null)?->rate,
            Tax::find($invoice['pph_tax_id'] ?? null)?->rate,
            $currency,
        );
        $invoice['subtotal_amount'] = CurrencyFormatter::formatFormState($subtotal, $currency);
        $invoice['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_addition'], $currency);
        $invoice['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['tax_deduction'], $currency);
        $invoice['grand_total_amount'] = CurrencyFormatter::formatFormStateNoDecimals($amounts['grand_total'], $currency);
    }

    /** @param array<string, mixed> $invoice */
    private static function recalculateItemizedInvoiceState(array &$invoice, ?string $changedItemKey = null, ?string $changedField = null, string $currency = 'IDR', bool $general = false): void
    {
        $taxIds = collect($invoice['items'])
            ->flatMap(fn (array $item): array => [$item['ppn_tax_id'] ?? null, $item['pph_tax_id'] ?? null])
            ->filter()
            ->unique()
            ->all();
        $taxes = Tax::query()->whereIn('id', $taxIds)->get()->keyBy('id');
        $invoiceSubtotal = 0.0;
        $invoicePpn = 0.0;
        $invoicePph = 0.0;

        foreach ($invoice['items'] as $key => &$item) {
            $subtotal = self::money($item['quantity'] ?? 0) * self::money($item['unit_price_amount'] ?? 0);
            if ($general) {
                $subtotal = MoneyRounding::round($subtotal, $currency);
            }
            $ppn = $taxes->get($item['ppn_tax_id'] ?? null);
            $pph = $taxes->get($item['pph_tax_id'] ?? null);
            $calculated = InvoiceAmountCalculator::calculateForCurrency(
                $subtotal,
                $ppn?->calculation_type === 'addition' ? (float) $ppn->rate : null,
                $pph?->calculation_type === 'deduction' ? (float) $pph->rate : null,
                $currency,
            );

            $addition = $ppn ? $calculated['tax_addition'] : self::money($item['tax_addition_amount'] ?? 0);
            $deduction = $pph ? $calculated['tax_deduction'] : self::money($item['tax_deduction_amount'] ?? 0);
            if ((! isset($item['id']) || ($key === $changedItemKey && $changedField === 'ppn_tax_id')) && blank($item['ppn_tax_id'] ?? null)) {
                $addition = 0.0;
            }
            if ((! isset($item['id']) || ($key === $changedItemKey && $changedField === 'pph_tax_id')) && blank($item['pph_tax_id'] ?? null)) {
                $deduction = 0.0;
            }

            $item['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($addition, $currency);
            $item['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($deduction, $currency);
            $item['net_amount'] = CurrencyFormatter::formatFormState($subtotal + $addition - $deduction, $currency);
            $invoiceSubtotal += $subtotal;
            $invoicePpn += $addition;
            $invoicePph += $deduction;
        }
        unset($item);

        $invoice['subtotal_amount'] = CurrencyFormatter::formatFormState($invoiceSubtotal, $currency);
        $invoice['tax_addition_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoicePpn, $currency);
        $invoice['tax_deduction_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoicePph, $currency);
        $invoice['grand_total_amount'] = CurrencyFormatter::formatFormStateNoDecimals($invoiceSubtotal + $invoicePpn - $invoicePph, $currency);
    }

    public static function money(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            $hasComma = str_contains($value, ',');
            $hasDot = str_contains($value, '.');

            if ($hasDot && ! $hasComma) {
                // E.g. "11.000" or "1.500.000"
                if (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $value)) {
                    return (float) str_replace('.', '', $value);
                }

                return (float) $value;
            }

            if ($hasComma && ! $hasDot) {
                // E.g. "11,000" or "1,500,000"
                if (preg_match('/^-?\d{1,3}(?:,\d{3})+$/', $value)) {
                    return (float) str_replace(',', '', $value);
                }

                return (float) str_replace(',', '.', $value);
            }

            if ($hasComma && $hasDot) {
                $lastComma = strrpos($value, ',');
                $lastDot = strrpos($value, '.');

                if ($lastDot > $lastComma) {
                    // USD style: 1,500.50
                    return (float) str_replace(',', '', $value);
                } else {
                    // IDR style: 1.500,50
                    return (float) str_replace(['.', ','], ['', '.'], $value);
                }
            }

            return (float) $value;
        }

        return (float) ($value ?? 0);
    }
}
