<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;

class GeneralPaymentSlipData
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prepareCreate(array $data): array
    {
        unset($data['invoice_receipt_number'], $data['buyer_id']);

        $data['transaction_type'] = PaymentSlip::TYPE_GENERAL;
        $data['tax_calculation_mode'] = PaymentSlip::TAX_MODE_INVOICE_LEGACY;
        $data['currency'] ??= PaymentSlip::CURRENCY_IDR;

        return $data;
    }

    /**
     * Keep the existing record type when editing through the shared URL.
     * Unlike creation, editing must not rewrite legacy tax modes or invoice data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareEdit(array $data): array
    {
        unset($data['invoice_receipt_number'], $data['buyer_id']);
        $data['transaction_type'] = PaymentSlip::TYPE_GENERAL;

        return $data;
    }

    /**
     * Internal invoice-level handoff for the future PO adapter. The manual form
     * does not call this method, so existing invoices remain unlinked by default.
     *
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public static function withOptionalPurchaseOrder(array $invoice, ?int $purchaseOrderId): array
    {
        if ($purchaseOrderId !== null) {
            $invoice['purchase_order_id'] = $purchaseOrderId;
        }

        return $invoice;
    }
}
