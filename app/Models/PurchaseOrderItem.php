<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'line_number',
        'item_id',
        'item_code_snapshot',
        'item_name_snapshot',
        'specification_snapshot',
        'item_code',
        'item_name',
        'specification',
        'quantity',
        'unit_id',
        'unit_code_snapshot',
        'unit_price_amount',
        'subtotal_amount',
        'tax_amount',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price_amount' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrderItem $item): void {
            if (! $item->line_number) {
                $item->line_number = ((int) static::query()
                    ->where('purchase_order_id', $item->purchase_order_id)
                    ->max('line_number')) + 1;
            }

            if ($item->item_id && ($item->isDirty('item_id') || blank($item->item_name_snapshot))) {
                $masterItem = Item::query()->find($item->item_id);

                if ($masterItem) {
                    $item->item_code_snapshot = $masterItem->source_code ?: $masterItem->code;
                    $item->item_name_snapshot = $masterItem->name;
                    $item->specification_snapshot = $masterItem->specification;
                    $item->item_code = $masterItem->code;
                    $item->item_name = $masterItem->name;
                    // Spesifikasi yang sudah diketik user tidak boleh ditimpa nilai master.
                    // Satu barang sering dipakai dengan ukuran berbeda tiap pembelian
                    // (mis. POLYBAG), jadi nilai master hanya dipakai untuk mengisi kolom kosong.
                    if (blank($item->specification)) {
                        $item->specification = $masterItem->specification;
                    }
                    $item->unit_id ??= $masterItem->unit_id;
                }
            }

            if ($item->unit_id && ($item->isDirty('unit_id') || blank($item->unit_code_snapshot))) {
                $item->unit_code_snapshot = Unit::query()->whereKey($item->unit_id)->value('code');
            }

            $item->subtotal_amount = round((float) $item->quantity * (float) $item->unit_price_amount, 2);
            $item->tax_amount = round((float) ($item->tax_amount ?? 0), 2);
            $item->total_amount = $item->subtotal_amount + $item->tax_amount;
        });

        static::saved(function (PurchaseOrderItem $item): void {
            // Filament dapat menyimpan ulang seluruh repeater walaupun nilainya tidak berubah.
            // Jangan menghitung ulang total historis ECOUNT hanya karena field header diedit.
            if ($item->wasRecentlyCreated || $item->wasChanged([
                'quantity',
                'unit_price_amount',
                'subtotal_amount',
                'tax_amount',
            ])) {
                $item->recalculateTaxes();
            }
        });
        static::deleted(fn (PurchaseOrderItem $item) => $item->purchaseOrder?->recalculateTotals());
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemTax::class);
    }

    public function selectedTaxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'purchase_order_item_taxes')
            ->using(PurchaseOrderItemTax::class)
            ->withPivot([
                'tax_code_snapshot',
                'tax_name_snapshot',
                'rate_snapshot',
                'calculation_type_snapshot',
                'taxable_amount',
                'tax_amount',
            ])
            ->withTimestamps();
    }

    public function additionTaxes(): BelongsToMany
    {
        return $this->selectedTaxes()
            ->where('taxes.calculation_type', 'addition');
    }

    public function deductionTaxes(): BelongsToMany
    {
        return $this->selectedTaxes()
            ->where('taxes.calculation_type', 'deduction');
    }

    public function recalculateTaxes(): void
    {
        $subtotal = (float) $this->subtotal_amount;
        $taxes = $this->taxes()->get();

        foreach ($taxes as $tax) {
            $tax->forceFill([
                'taxable_amount' => $subtotal,
                'tax_amount' => round($subtotal * ((float) $tax->rate_snapshot / 100), 2),
            ])->saveQuietly();
        }

        $addition = (float) $taxes
            ->where('calculation_type_snapshot', 'addition')
            ->sum('tax_amount');
        $deduction = (float) $taxes
            ->where('calculation_type_snapshot', 'deduction')
            ->sum('tax_amount');
        $netTax = $addition - $deduction;

        $this->forceFill([
            'tax_amount' => $netTax,
            'total_amount' => $subtotal + $netTax,
        ])->saveQuietly();

        $this->purchaseOrder?->recalculateTotals();
    }
}
