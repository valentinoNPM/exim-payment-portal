<?php

namespace App\Http\Requests;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $purchaseOrder = $this->route('purchaseOrder');

        return $purchaseOrder instanceof PurchaseOrder
            ? PurchaseOrderResource::canEdit($purchaseOrder)
            : PurchaseOrderResource::canCreate();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $purchaseOrder = $this->route('purchaseOrder');
        $existingTaxIds = $purchaseOrder instanceof PurchaseOrder
            ? $purchaseOrder->taxes()->pluck('tax_id')->all()
            : [];
        $existingWarehouseId = $purchaseOrder instanceof PurchaseOrder
            ? $purchaseOrder->warehouse_id
            : null;

        return [
            'po_date' => ['required', 'date'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'pic_user_id' => ['required', 'integer', 'exists:users,id'],
            'currency' => ['required', Rule::in(PurchaseOrder::CURRENCIES)],
            'addition_tax_id' => [
                'nullable',
                'integer',
                Rule::exists('taxes', 'id')->where(fn ($query) => $query
                    ->where('calculation_type', 'addition')
                    ->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->when($existingTaxIds !== [], fn ($query) => $query->orWhereIn('id', $existingTaxIds)))),
            ],
            'deduction_tax_id' => [
                'nullable',
                'integer',
                Rule::exists('taxes', 'id')->where(fn ($query) => $query
                    ->where('calculation_type', 'deduction')
                    ->where(fn ($query) => $query
                        ->where('is_active', true)
                        ->when($existingTaxIds !== [], fn ($query) => $query->orWhereIn('id', $existingTaxIds)))),
            ],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_keys(PurchaseOrder::STATUS_LABELS))],
            'delivery_date' => ['nullable', 'date'],
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->when($existingWarehouseId, fn ($query, $id) => $query->orWhere('id', $id))),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.id' => ['nullable', 'integer', 'exists:purchase_order_items,id'],
            'items.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'items.*.specification' => ['nullable', 'string', 'max:500'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.unit_price_amount' => ['required', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $purchaseOrder = $this->route('purchaseOrder');

            foreach ($this->input('items', []) as $index => $row) {
                $lineId = filled($row['id'] ?? null) ? (int) $row['id'] : null;
                $itemId = filled($row['item_id'] ?? null) ? (int) $row['item_id'] : null;
                $unitId = filled($row['unit_id'] ?? null) ? (int) $row['unit_id'] : null;
                $existing = $lineId
                    ? PurchaseOrderItem::query()->find($lineId)
                    : null;

                if ($existing && (
                    ! $purchaseOrder instanceof PurchaseOrder
                    || (int) $existing->purchase_order_id !== (int) $purchaseOrder->getKey()
                )) {
                    $validator->errors()->add("items.{$index}.id", 'Baris item tidak termasuk dalam PO ini.');
                }

                if (! $itemId && (! $existing || $existing->item_id !== null)) {
                    $validator->errors()->add("items.{$index}.item_id", 'Barang wajib dipilih.');
                }

                $isNewMasterSelection = ! $lineId
                    || ($existing && (int) ($existing->item_id ?? 0) !== (int) ($itemId ?? 0));

                if ($itemId && $isNewMasterSelection && ! $unitId) {
                    $validator->errors()->add("items.{$index}.unit_id", 'Satuan wajib dipilih.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'vendor',
            'pic_user_id' => 'PIC',
            'po_date' => 'tanggal PO',
            'delivery_date' => 'tanggal pengiriman',
            'warehouse_id' => 'gudang',
            'items.*.item_id' => 'barang',
            'items.*.notes' => 'keterangan item',
            'items.*.quantity' => 'kuantitas',
            'items.*.unit_id' => 'satuan',
            'items.*.unit_price_amount' => 'harga',
        ];
    }
}
