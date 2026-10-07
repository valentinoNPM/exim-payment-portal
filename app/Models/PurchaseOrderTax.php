<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class PurchaseOrderTax extends Pivot
{
    protected $table = 'purchase_order_taxes';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $fillable = [
        'purchase_order_id',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
