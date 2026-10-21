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
            if ($type === PaymentSlip::TYPE_GENERAL) {
                $data['approval_incharge'] = filled($data['approval_incharge'] ?? null)
                    ? trim($data['approval_incharge'])
                    : auth()->user()?->name;
            }

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
                'approval_incharge' => $type === PaymentSlip::TYPE_GENERAL ? ($prepared['approval_incharge'] ?? null) : null,
                'approval_passed_1' => $type === PaymentSlip::TYPE_GENERAL ? ($prepared['approval_passed_1'] ?? null) : null,
                'approval_passed_2' => $type === PaymentSlip::TYPE_GENERAL ? ($prepared['approval_passed_2'] ?? null) : null,
                'approval_passed_3' => $type === PaymentSlip::TYPE_GENERAL ? ($prepared['approval_passed_3'] ?? null) : null,
                'approval_director' => $type === PaymentSlip::TYPE_GENERAL ? ($prepared['approval_director'] ?? null) : null,
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
