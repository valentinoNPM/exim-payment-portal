<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PurchaseOrderItemTax extends Pivot
{
    protected $table = 'purchase_order_item_taxes';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'purchase_order_item_id',
        'tax_id',
        'tax_code_snapshot',
        'tax_name_snapshot',
        'rate_snapshot',
        'calculation_type_snapshot',
        'taxable_amount',
        'tax_amount',
    ];

    protected $casts = [
        'rate_snapshot' => 'decimal:4',
        'taxable_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (PurchaseOrderItemTax $purchaseOrderItemTax): void {
            $tax = Tax::query()->findOrFail($purchaseOrderItemTax->tax_id);
            $item = PurchaseOrderItem::query()->findOrFail($purchaseOrderItemTax->purchase_order_item_id);

            $purchaseOrderItemTax->tax_code_snapshot = $tax->code;
            $purchaseOrderItemTax->tax_name_snapshot = $tax->name;
            $purchaseOrderItemTax->rate_snapshot = $tax->rate;
            $purchaseOrderItemTax->calculation_type_snapshot = $tax->calculation_type;
            $purchaseOrderItemTax->taxable_amount = $item->subtotal_amount;
            $purchaseOrderItemTax->tax_amount = round(
                (float) $item->subtotal_amount * ((float) $tax->rate / 100),
                2,
            );
        });

        static::saved(fn (PurchaseOrderItemTax $tax): mixed => $tax->purchaseOrderItem?->recalculateTaxes());
        static::deleted(fn (PurchaseOrderItemTax $tax): mixed => $tax->purchaseOrderItem?->recalculateTaxes());
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
