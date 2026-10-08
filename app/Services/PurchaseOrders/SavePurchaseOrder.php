<?php

namespace App\Services\PurchaseOrders;

use App\Models\Division;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SavePurchaseOrder
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor, ?PurchaseOrder $purchaseOrder = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor, $purchaseOrder): PurchaseOrder {
            $isCreating = ! $purchaseOrder;
            $purchaseOrder = $purchaseOrder
                ? PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->getKey())
                : new PurchaseOrder;

            $picUser = User::query()->findOrFail($data['pic_user_id']);
            $oldPicUserId = $purchaseOrder->pic_user_id;

            $header = Arr::only($data, [
                'supplier_id',
                'pic_user_id',
                'currency',
                'delivery_date',
                'warehouse_id',
                'status',
                'discount_amount',
                'shipping_amount',
            ]);
            $header['discount_amount'] = $header['discount_amount'] ?? 0;
            $header['shipping_amount'] = $header['shipping_amount'] ?? 0;

            if ($isCreating) {
                $header['po_date'] = $data['po_date'];
                $header['division_id'] = Division::query()
                    ->whereRaw('UPPER(code) = ?', ['GA'])
                    ->value('id');
                $header['created_by'] = $actor->getKey();
                $header['pic_name'] = $picUser->name;
                $header['source'] = 'manual';

                abort_unless($header['division_id'], 422, 'Division GA belum tersedia.');
            } elseif ($oldPicUserId !== null && (int) $oldPicUserId !== (int) $picUser->getKey()) {
                $header['pic_name'] = $picUser->name;
            } elseif (blank($purchaseOrder->pic_name)) {
                $header['pic_name'] = $picUser->name;
            }

            $purchaseOrder->fill($header);
            $purchaseOrder->save();

            $totalsNeedRefresh = $isCreating || $purchaseOrder->wasChanged([
                'discount_amount',
                'shipping_amount',
            ]);
            $taxIds = array_values(array_filter([
                $data['addition_tax_id'] ?? null,
                $data['deduction_tax_id'] ?? null,
            ]));

            if (! $isCreating && $purchaseOrder->items()->exists()) {
                DB::table('purchase_order_items')
                    ->where('purchase_order_id', $purchaseOrder->getKey())
                    ->increment('line_number', 1000000);
            }

            $existingItems = $purchaseOrder->items()->get()->keyBy('id');
            $keptIds = [];

            $rows = $data['items'];
            ksort($rows, SORT_NUMERIC);

            foreach (array_values($rows) as $index => $row) {
                $lineId = filled($row['id'] ?? null) ? (int) $row['id'] : null;
                /** @var PurchaseOrderItem $line */
                $line = $lineId ? $existingItems->get($lineId) : new PurchaseOrderItem;

                abort_unless($line, 422, 'Baris item PO tidak ditemukan.');

                if (! $line->exists) {
                    $line->purchase_order_id = $purchaseOrder->getKey();
                }

                $line->fill([
                    'line_number' => $index + 1,
                    'item_id' => $row['item_id'] ?? null,
                    'specification' => $row['specification'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'quantity' => $row['quantity'],
                    'unit_id' => $row['unit_id'] ?? null,
                    'unit_price_amount' => $row['unit_price_amount'],
                ]);
                $line->save();

                $keptIds[] = $line->getKey();
                $amountChanged = $line->wasRecentlyCreated || $line->wasChanged([
                    'quantity',
                    'unit_price_amount',
                    'subtotal_amount',
                ]);
                $taxChanges = $line->selectedTaxes()->sync($taxIds);
                $taxChanged = $taxChanges['attached'] !== []
                    || $taxChanges['detached'] !== []
                    || $taxChanges['updated'] !== [];

                if ($line->wasRecentlyCreated || $taxChanged) {
                    $line->recalculateTaxes();
                }

                $totalsNeedRefresh = $totalsNeedRefresh || $amountChanged || $taxChanged;
            }

            $removedItems = $existingItems->whereNotIn('id', $keptIds);
            foreach ($removedItems as $removedItem) {
                $removedItem->delete();
            }
            $totalsNeedRefresh = $totalsNeedRefresh || $removedItems->isNotEmpty();

            if ($totalsNeedRefresh) {
                $purchaseOrder->recalculateTotals();
            }

            return $purchaseOrder->fresh(['items.unit', 'supplier', 'taxes', 'warehouse']);
        });
    }
}
