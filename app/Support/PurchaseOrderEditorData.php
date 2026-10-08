<?php

namespace App\Support;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseOrders\PurchaseOrderNumberGenerator;
use Illuminate\Support\Collection;

class PurchaseOrderEditorData
{
    /** @return array<string, mixed> */
    public function for(?PurchaseOrder $purchaseOrder = null): array
    {
        $purchaseOrder?->loadMissing(['items.item.unit', 'items.unit', 'taxes', 'warehouse']);

        $additionTaxId = $purchaseOrder?->taxes
            ->firstWhere('calculation_type_snapshot', 'addition')?->tax_id;
        $deductionTaxId = $purchaseOrder?->taxes
            ->firstWhere('calculation_type_snapshot', 'deduction')?->tax_id;
        $rows = old('items');

        if (! is_array($rows)) {
            $rows = $purchaseOrder
                ? $purchaseOrder->items->map(fn ($line): array => [
                    'id' => $line->getKey(),
                    'item_id' => $line->item_id,
                    'item_code' => $line->item_code_snapshot ?: $line->item_code,
                    'item_name' => $line->item_name_snapshot ?: $line->item_name,
                    'specification' => $line->specification ?: $line->specification_snapshot,
                    'quantity' => $line->quantity,
                    'unit_id' => $line->unit_id,
                    'unit_name' => $line->unit?->name,
                    'unit_price_amount' => $line->unit_price_amount,
                ])->all()
                : [[
                    'id' => null,
                    'item_id' => null,
                    'item_code' => null,
                    'item_name' => null,
                    'specification' => null,
                    'quantity' => 1,
                    'unit_id' => null,
                    'unit_name' => null,
                    'unit_price_amount' => 0,
                ]];
        }

        $rows = $this->enrichRows($rows, $purchaseOrder);
        $selectedUnitIds = collect($rows)->pluck('unit_id')->filter()->map(fn ($id): int => (int) $id);
        $selectedSupplierId = old('supplier_id', $purchaseOrder?->supplier_id);
        $selectedWarehouseId = old('warehouse_id', $purchaseOrder?->warehouse_id);
        $selectedTaxIds = collect([
            old('addition_tax_id', $additionTaxId),
            old('deduction_tax_id', $deductionTaxId),
        ])->filter();

        return [
            'record' => $purchaseOrder,
            'isEdit' => (bool) $purchaseOrder,
            'draftNumber' => $purchaseOrder?->po_number
                ?? app(PurchaseOrderNumberGenerator::class)->preview(today())['po_number'],
            'suppliers' => Supplier::query()
                ->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($selectedSupplierId, fn ($query, $id) => $query->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()
                ->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($selectedWarehouseId, fn ($query, $id) => $query->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['id', 'source_code', 'name']),
            'units' => Unit::query()
                ->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($selectedUnitIds->isNotEmpty(), fn ($query) => $query->orWhereIn('id', $selectedUnitIds)))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'additionTaxes' => $this->taxes('addition', $selectedTaxIds),
            'deductionTaxes' => $this->taxes('deduction', $selectedTaxIds),
            'additionTaxId' => $additionTaxId,
            'deductionTaxId' => $deductionTaxId,
            'initialItems' => $rows,
        ];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichRows(array $rows, ?PurchaseOrder $purchaseOrder): array
    {
        $itemIds = collect($rows)->pluck('item_id')->filter()->unique();
        $unitIds = collect($rows)->pluck('unit_id')->filter()->unique();
        $lineIds = collect($rows)->pluck('id')->filter()->unique();
        $items = Item::query()->with('unit:id,name')->whereIn('id', $itemIds)->get()->keyBy('id');
        $units = Unit::query()->whereIn('id', $unitIds)->get(['id', 'name'])->keyBy('id');
        $lines = $purchaseOrder
            ? $purchaseOrder->items->whereIn('id', $lineIds)->keyBy('id')
            : collect();

        return collect($rows)->values()->map(function (array $row) use ($items, $units, $lines): array {
            $master = $items->get($row['item_id'] ?? null);
            $line = $lines->get($row['id'] ?? null);

            return [
                'id' => $row['id'] ?? null,
                'item_id' => $row['item_id'] ?? $line?->item_id,
                'item_code' => $master?->source_code
                    ?: ($master?->code ?: ($line?->item_code_snapshot ?: $line?->item_code)),
                'item_name' => $master?->name
                    ?: ($line?->item_name_snapshot ?: $line?->item_name),
                'specification' => $row['specification']
                    ?? $line?->specification
                    ?? $line?->specification_snapshot
                    ?? $master?->specification,
                'quantity' => $row['quantity'] ?? $line?->quantity ?? 1,
                'unit_id' => $row['unit_id'] ?? $line?->unit_id ?? $master?->unit_id,
                'unit_name' => $units->get($row['unit_id'] ?? null)?->name
                    ?? $line?->unit?->name
                    ?? $master?->unit?->name,
                'unit_price_amount' => $row['unit_price_amount'] ?? $line?->unit_price_amount ?? 0,
            ];
        })->all();
    }

    /** @return Collection<int, Tax> */
    private function taxes(string $type, Collection $selectedTaxIds): Collection
    {
        return Tax::query()
            ->where('calculation_type', $type)
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->when($selectedTaxIds->isNotEmpty(), fn ($query) => $query->orWhereIn('id', $selectedTaxIds)))
            ->orderBy('name')
            ->get();
    }
}
