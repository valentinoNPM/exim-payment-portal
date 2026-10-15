<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use Illuminate\Validation\ValidationException;

class EximPaymentSlipData
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prepareCreate(array $data): array
    {
        unset($data['customer_id'], $data['transaction_description']);

        $data['transaction_type'] ??= PaymentSlip::TYPE_EXPORT;
        if (! in_array($data['transaction_type'], [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true)) {
            throw ValidationException::withMessages(['transaction_type' => 'Tipe transaksi EXIM tidak valid.']);
        }
        $data['tax_calculation_mode'] = PaymentSlip::TAX_MODE_ITEMIZED;
        $data['currency'] = PaymentSlip::CURRENCY_IDR;

        return $data;
    }

    /**
     * Preserve Import/Export identity and historical tax mode on edit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareEdit(array $data, PaymentSlip $record): array
    {
        unset($data['customer_id'], $data['transaction_description']);
        $data['transaction_type'] = $record->transaction_type;

        return $data;
    }
}
