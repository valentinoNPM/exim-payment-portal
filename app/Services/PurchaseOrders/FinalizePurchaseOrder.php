<?php

namespace App\Services\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinalizePurchaseOrder
{
    public function execute(PurchaseOrder $purchaseOrder, User $actor, bool $reopen = false): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $actor, $reopen): PurchaseOrder {
            $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            self::authorize($po, $actor);
            if (! $po->isPaymentSlipEligibleSource() || $po->hasIssuedPaymentSlip()) {
                throw ValidationException::withMessages(['purchase_order' => 'PO historis atau yang telah diterbitkan tidak dapat difinalisasi/dibuka kembali.']);
            }
            if (! $reopen) {
                self::validateSource($po);
            }
            $po->forceFill(['finalized_at' => $reopen ? null : now(), 'finalized_by' => $reopen ? null : $actor->id])->saveQuietly();
            $po->audits()->create(['user_id' => $actor->id, 'event' => $reopen ? 'reopened' : 'finalized', 'new_values' => ['finalized_at' => $po->finalized_at]]);

            return $po;
        });
    }

    public static function authorize(PurchaseOrder $po, User $actor): void
    {
        abort_unless($actor->hasRole('maker') && $actor->isGaDivision() && strtoupper((string) $po->division?->code) === 'GA', 403);
    }

    public static function validateSource(PurchaseOrder $po): void
    {
        $po->loadMissing('items.taxes');
        if (! $po->supplier_id || ! $po->items->count() || ! in_array($po->currency, PurchaseOrder::CURRENCIES, true) || $po->grand_total_amount < 0) {
            throw ValidationException::withMessages(['purchase_order' => 'Lengkapi supplier, mata uang dan item PO dengan total nonnegatif sebelum finalisasi.']);
        }
        foreach ($po->items as $item) {
            $name = trim($item->item_name_snapshot ?: $item->item_name);
            $name .= filled($item->specification) ? ' ['.$item->specification.']' : '';
            if (blank($name) || mb_strlen($name) > 255 || ! $item->unit_id || $item->quantity <= 0 || $item->unit_price_amount < 0 || $item->taxes->groupBy('calculation_type_snapshot')->contains(fn ($taxes) => $taxes->count() > 1)) {
                throw ValidationException::withMessages(['purchase_order' => 'Periksa nama + spesifikasi (maksimal 255 karakter), satuan, kuantitas, harga dan pajak item PO.']);
            }
        }
    }
}
