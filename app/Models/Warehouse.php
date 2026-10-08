<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master gudang/lokasi dari ECOUNT.
 *
 * `source_code` = "Kode Lokasi" ECOUNT ('00001', 'GA', 'WH', 'SP', ...). Dipakai sebagai
 * identitas master, sama seperti kode barang (items.source_code) dan kode supplier.
 */
class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_code',
        'name',
        'type',
        'is_active',
        'branch',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
