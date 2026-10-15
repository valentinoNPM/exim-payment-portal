<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use App\Support\MoneyRounding;
use Illuminate\Validation\ValidationException;

/** Validates stored payment amounts, without recalculating tax from today's master. */
final class ValidateGeneralPaymentSlip
{
    public function validate(PaymentSlip $slip): void
    {
        $this->require($slip->transaction_type === PaymentSlip::TYPE_GENERAL, 'Jenis slip harus General.');
        $currency = $slip->currency ?? 'IDR';
        $this->require(in_array($currency, ['IDR', 'USD', 'EUR'], true), 'Mata uang General tidak valid.');
        $slip->loadMissing(['supplier', 'invoices.items', 'invoices.purchaseOrder']);
        $this->require($slip->supplier !== null, 'Supplier wajib tersedia.');
        $this->require($slip->invoices->isNotEmpty(), 'Slip harus memiliki invoice.');
        foreach ($slip->invoices as $invoice) {
            $context = 'Invoice '.($invoice->invoice_number ?: $invoice->id);
            $this->require(filled($invoice->invoice_number) && $invoice->invoice_date !== null, $context.': nomor dan tanggal wajib diisi.');
            $this->require($invoice->items->isNotEmpty(), $context.': item wajib tersedia.');
            foreach ($invoice->items as $item) {
                $this->require(filled($item->item_name) && is_numeric($item->quantity) && (float) $item->quantity > 0, $context.': nama dan kuantitas item tidak valid.');
                foreach (['unit_price_amount', 'subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'net_amount'] as $field) {
                    $this->amount($item->{$field}, $context);
                }
                // Signed historical items are preserved; no name-based discount classification.
                $this->equal($item->net_amount, (float) $item->subtotal_amount + (float) $item->tax_addition_amount - (float) $item->tax_deduction_amount, $currency, $context.': total item tidak konsisten.');
            }
            foreach (['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount', 'discount_amount', 'shipping_amount'] as $field) {
                $this->amount($invoice->{$field}, $context);
            }
            $this->equal($invoice->subtotal_amount, $invoice->items->sum('subtotal_amount'), $currency, $context.': subtotal tidak cocok dengan item.');
            if ($invoice->usesItemizedTaxes()) {
                foreach (['tax_addition_amount', 'tax_deduction_amount'] as $field) {
                    $this->equal($invoice->{$field}, $invoice->items->sum($field), $currency, $context.': pajak tidak cocok dengan item.');
                }
            }
            $expected = GeneralInvoiceTotal::calculate(
                (float) $invoice->subtotal_amount, (float) $invoice->tax_addition_amount,
                (float) $invoice->tax_deduction_amount, (float) $invoice->discount_amount,
                (float) $invoice->shipping_amount, $currency,
            );
            $this->equal($invoice->grand_total_amount, $expected, $currency, $context.': total termasuk diskon/ongkir tidak konsisten.');
            if ($invoice->purchase_order_id) {
                $po = $invoice->purchaseOrder;
                $this->require($po !== null && $po->finalized_at !== null && (int) $po->supplier_id === (int) $slip->supplier_id && $po->currency === $currency, $context.': sumber PO tidak valid.');
                foreach (['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'discount_amount', 'shipping_amount', 'grand_total_amount'] as $field) {
                    $this->equal($invoice->{$field}, $po->{$field}, $currency, $context.': nominal sumber PO berubah ('.$field.').');
                }
            }
        }
        foreach (['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount'] as $field) {
            $this->amount($slip->{$field}, 'Slip');
            $this->equal($slip->{$field}, $slip->invoices->sum($field), $currency, 'Total slip tidak cocok dengan invoice ('.$field.').');
        }
    }

    private function amount(mixed $value, string $context): void
    {
        $this->require(is_numeric($value) && is_finite((float) $value) && abs((float) $value) < 1e16, $context.': nominal tidak valid.');
    }

    private function equal(mixed $actual, mixed $expected, string $currency, string $message): void
    {
        $this->require(abs(MoneyRounding::round((float) $actual, $currency) - MoneyRounding::round((float) $expected, $currency)) < 0.0001, $message);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['verification' => $message]);
        }
    }
}
