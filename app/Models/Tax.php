<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tax extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'rate',
        'calculation_type',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function invoiceItemTaxes(): HasMany
    {
        return $this->hasMany(InvoiceItemTax::class);
    }

    public function purchaseOrderTaxes(): HasMany
    {
        return $this->hasMany(PurchaseOrderTax::class);
    }

    public function purchaseOrderItemTaxes(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemTax::class);
    }
}
