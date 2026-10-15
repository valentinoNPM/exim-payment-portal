<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use Illuminate\Validation\ValidationException;

/** Guard unsupported General combinations; does not alter the EXIM journal contract. */
final class GeneralErpExportEligibility
{
    public function validate(PaymentSlip $slip): void
    {
        if ($slip->transaction_type !== PaymentSlip::TYPE_GENERAL) {
            return;
        }
        $slip->loadMissing('invoices.items');
        $reason = null;
        if (($slip->currency ?? 'IDR') !== 'IDR') {
            $reason = 'Ekspor ERP General USD/EUR belum didukung; aturan mata uang/kurs belum ditetapkan.';
        } elseif ($slip->invoices->contains(fn ($invoice): bool => (float) $invoice->discount_amount != 0 || (float) $invoice->shipping_amount != 0)) {
            $reason = 'Ekspor ERP General dengan diskon/ongkir invoice belum didukung; pemetaan jurnal belum tersedia.';
        } elseif ($slip->invoices->contains(fn ($invoice): bool => $invoice->items->contains(fn ($item): bool => (float) $item->subtotal_amount < 0 || (float) $item->net_amount < 0))) {
            $reason = 'Ekspor ERP General dengan item negatif belum didukung; data historis tetap dipertahankan.';
        }
        if ($reason !== null) {
            throw ValidationException::withMessages(['erp' => $reason.' Verifikasi pembayaran tetap dapat diselesaikan tanpa ekspor ERP.']);
        }
    }
}
