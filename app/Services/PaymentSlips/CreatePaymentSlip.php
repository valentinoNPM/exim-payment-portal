<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use Illuminate\Support\Facades\DB;

final class CreatePaymentSlip
{
    /** @param array<string, mixed> $data */
    public function execute(string $type, array $data, SavePaymentSlipEdit $save): PaymentSlip
    {
        return DB::transaction(function () use ($type, $data, $save): PaymentSlip {
            $last = PaymentSlip::query()
                ->where('slip_number', 'like', 'PS-%-HANSOLL-%')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            $sequence = $last ? ((int) (explode('-', $last->slip_number)[1] ?? 0) + 1) : 1;

            $prepared = $type === PaymentSlip::TYPE_GENERAL
                ? GeneralPaymentSlipData::prepareCreate($data)
                : EximPaymentSlipData::prepareCreate(['transaction_type' => $type] + $data);

            $slip = PaymentSlip::create([
                'slip_number' => sprintf('PS-%05d-HANSOLL-%s', $sequence, date('Y')),
                'transaction_type' => $type,
                'transaction_description' => $prepared['transaction_description'] ?? null,
                'invoice_receipt_number' => $prepared['invoice_receipt_number'] ?? null,
                'currency' => $prepared['currency'],
                'tax_calculation_mode' => $prepared['tax_calculation_mode'],
                'supplier_id' => $prepared['supplier_id'],
                'customer_id' => $prepared['customer_id'] ?? null,
                'buyer_id' => $prepared['buyer_id'] ?? null,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            return $save->execute($slip, $data);
        });
    }
}
