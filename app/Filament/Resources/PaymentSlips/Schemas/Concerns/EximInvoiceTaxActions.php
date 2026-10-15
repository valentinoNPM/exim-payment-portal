<?php

namespace App\Filament\Resources\PaymentSlips\Schemas\Concerns;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Tax;
use App\Services\PaymentSlips\InvoiceDraftCalculator;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

trait EximInvoiceTaxActions
{
    protected const CLEAR_BULK_TAX = '__clear_tax__';

    protected static function hasItemTaxConflict(Get $get): bool
    {
        foreach (($get('items') ?? []) as $item) {
            if (filled($item['ppn_tax_id'] ?? null)
                || filled($item['pph_tax_id'] ?? null)
                || self::money($item['tax_addition_amount'] ?? 0) !== 0.0
                || self::money($item['tax_deduction_amount'] ?? 0) !== 0.0) {
                return true;
            }
        }

        return false;
    }

    protected static function applyInvoiceTax(
        Get $get,
        Set $set,
        ?object $record,
        string $taxField,
        string $selectionField,
        string $calculationType,
    ): void {
        $invoice = $record instanceof Invoice ? $record : null;
        $paymentSlip = self::paymentSlipForActionRecord($record);
        abort_unless(self::isItemizedExim($get, $invoice) && (! $paymentSlip || $paymentSlip->status === 'draft'), 403);

        $selection = $get($selectionField);
        if (blank($selection)) {
            return;
        }

        $taxId = null;
        if ($selection !== self::CLEAR_BULK_TAX) {
            $taxId = Tax::query()
                ->whereKey($selection)
                ->where('calculation_type', $calculationType)
                ->where('is_active', true)
                ->value('id');
            if (! $taxId) {
                throw ValidationException::withMessages([$selectionField => 'Tarif pajak yang dipilih tidak valid atau tidak aktif.']);
            }
        }

        $items = $get('items') ?? [];
        if ($items === []) {
            return;
        }

        $type = $get('../../transaction_type');
        $hasImportDetails = $type === PaymentSlip::TYPE_IMPORT && collect($items)->contains(fn (array $item): bool => filled($item['source_supplier_name'] ?? null));
        $hasVatDetails = in_array($type, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true) && collect($items)->contains(fn (array $item): bool => filled($item['vat_invoice_number'] ?? null));

        if ($hasImportDetails || $hasVatDetails) {
            throw ValidationException::withMessages([$selectionField => 'Invoice dengan supplier pendukung atau VAT per item harus memakai pajak per item agar detail ERP tidak hilang.']);
        }

        $invoice = [
            'items' => $items,
            'ppn_tax_id' => $taxField === 'ppn_tax_id' ? $taxId : null,
            'pph_tax_id' => $taxField === 'pph_tax_id' ? $taxId : null,
        ];
        $otherSelectionField = $taxField === 'ppn_tax_id' ? 'bulk_pph_tax_id' : 'bulk_ppn_tax_id';
        $otherSelection = $get($otherSelectionField);
        if (filled($otherSelection) && $otherSelection !== self::CLEAR_BULK_TAX) {
            $otherType = $taxField === 'ppn_tax_id' ? 'deduction' : 'addition';
            $otherTaxId = Tax::query()->whereKey($otherSelection)->where('calculation_type', $otherType)->where('is_active', true)->value('id');
            if (! $otherTaxId) {
                throw ValidationException::withMessages([$otherSelectionField => 'Tarif pajak yang dipilih tidak valid atau tidak aktif.']);
            }
            $invoice[$taxField === 'ppn_tax_id' ? 'pph_tax_id' : 'ppn_tax_id'] = $otherTaxId;
        }

        foreach ($invoice['items'] as &$item) {
            $item['ppn_tax_id'] = null;
            $item['pph_tax_id'] = null;
            $item['tax_addition_amount'] = 0;
            $item['tax_deduction_amount'] = 0;
        }
        unset($item);

        $currency = $get('../../currency') ?? 'IDR';
        InvoiceDraftCalculator::recalculateInvoiceTaxState($invoice, $currency);
        $invoice['tax_calculation_mode'] = PaymentSlip::TAX_MODE_INVOICE_LEGACY;
        foreach (['items', 'tax_calculation_mode', 'ppn_tax_id', 'pph_tax_id', 'subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount'] as $field) {
            $set($field, $invoice[$field]);
        }
    }

    protected static function isItemizedExim(Get $get, ?Invoice $record): bool
    {
        return self::isEximInvoice($get, $record)
            && ($get('tax_calculation_mode') ?? ($record?->exists ? ($record->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY) : PaymentSlip::TAX_MODE_ITEMIZED)) === PaymentSlip::TAX_MODE_ITEMIZED;
    }

    /** @param array<string, mixed> $data */
    protected static function prepareInvoiceTaxMode(array $data, Get $get): array
    {
        return $data;
    }

    protected static function isItemizedItem(Get $get, ?InvoiceItem $record): bool
    {
        $type = $get('../../../../transaction_type');

        return in_array($type, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true)
            && ($get('../../tax_calculation_mode') ?? ($record?->exists ? ($record->invoice?->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY) : PaymentSlip::TAX_MODE_ITEMIZED)) === PaymentSlip::TAX_MODE_ITEMIZED;
    }

    protected static function isEximInvoice(Get $get, ?Invoice $record = null): bool
    {
        $type = $get('../../transaction_type');

        return $type === PaymentSlip::TYPE_IMPORT
            || ($type === PaymentSlip::TYPE_EXPORT
                && ($record?->paymentSlip?->usesItemizedTaxes()
                    ?? $get('../../tax_calculation_mode') === PaymentSlip::TAX_MODE_ITEMIZED));
    }
}
