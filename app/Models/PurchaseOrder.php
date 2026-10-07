<?php

namespace App\Models;

use App\Services\PurchaseOrders\PurchaseOrderNumberGenerator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SENT_TO_VENDOR = 'sent_to_vendor';

    public const STATUS_COMPLETED = 'completed';

    /**
     * Istilah status disamakan dengan yang dipakai klien di ECOUNT, karena itulah yang mereka baca
     * sehari-hari: Selesai, Dalam Proses, Belum Dikonfirmasi.
     * Keputusan val 5 Okt 2026 (cukup tiga, tanpa "Batal") disempurnakan 6 Okt 2026: dokumen ECOUNT
     * berstatus "Dalam Proses" tidak boleh dilabeli "Dikirim ke Vendor", jadi "Dalam Proses"
     * ditambahkan apa adanya.
     */
    public const STATUS_LABELS = [
        self::STATUS_NEW => 'Baru',
        self::STATUS_IN_PROGRESS => 'Dalam Proses',
        self::STATUS_SENT_TO_VENDOR => 'Dikirim ke Vendor',
        self::STATUS_COMPLETED => 'Selesai',
    ];

    public const CURRENCY_IDR = 'IDR';

    public const CURRENCY_USD = 'USD';

    public const CURRENCIES = [
        self::CURRENCY_IDR,
        self::CURRENCY_USD,
    ];

    public const CURRENCY_LABELS = [
        self::CURRENCY_IDR => 'IDR - Rupiah',
        self::CURRENCY_USD => 'USD - US Dollar',
    ];

    protected $fillable = [
        'po_number',
        'company_code',
        'sequence_number',
        'po_date',
        'division_id',
        'supplier_id',
        'supplier_name_snapshot',
        'supplier_address_snapshot',
        'pic_name',
        'pic_user_id',
        'currency',
        'delivery_date',
        'delivery_location',
        'title',
        'notes',
        'status',
        'subtotal_amount',
        'tax_amount',
        'tax_addition_amount',
        'tax_deduction_amount',
        'discount_amount',
        'shipping_amount',
        'grand_total_amount',
        'created_by',
        // jejak asal dokumen: 'source' = ecount/manual, 'source_code' = kunci ECOUNT
        // (identitas dokumen impor, mis. "01/09/2026 -4").
        'source',
        'source_code',
    ];

    protected $casts = [
        'sequence_number' => 'integer',
        'po_date' => 'date',
        'delivery_date' => 'date',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_addition_amount' => 'decimal:2',
        'tax_deduction_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'grand_total_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (PurchaseOrder $purchaseOrder): void {
            $currency = $purchaseOrder->currency ?? self::CURRENCY_IDR;

            if (! in_array($currency, self::CURRENCIES, true)) {
                throw ValidationException::withMessages([
                    'currency' => "Mata uang tidak valid: {$currency}. Pilihan yang tersedia: IDR atau USD.",
                ]);
            }

            $purchaseOrder->currency = $currency;
        });

        static::creating(function (PurchaseOrder $purchaseOrder): void {
            if (blank($purchaseOrder->supplier_name_snapshot) && $purchaseOrder->supplier_id) {
                $supplier = Supplier::query()->find($purchaseOrder->supplier_id);
                $purchaseOrder->supplier_name_snapshot = $supplier?->name;
                $purchaseOrder->supplier_address_snapshot = $supplier?->address;
            }

            if (blank($purchaseOrder->po_number)) {
                $number = app(PurchaseOrderNumberGenerator::class)->reserve(
                    Carbon::parse($purchaseOrder->po_date ?? now()),
                );

                $purchaseOrder->fill($number);
            }
        });

        static::created(function (PurchaseOrder $purchaseOrder): void {
            $purchaseOrder->audits()->create([
                'user_id' => auth()->id() ?? $purchaseOrder->created_by,
                'event' => 'created',
                'new_values' => $purchaseOrder->only([
                    'po_number', 'po_date', 'supplier_id', 'currency', 'status',
                ]),
            ]);
        });

        static::updated(function (PurchaseOrder $purchaseOrder): void {
            $changes = collect($purchaseOrder->getChanges())
                ->except(['subtotal_amount', 'tax_amount', 'grand_total_amount', 'updated_at'])
                ->all();

            if ($changes === []) {
                return;
            }

            $purchaseOrder->audits()->create([
                'user_id' => auth()->id(),
                'event' => 'updated',
                'old_values' => collect(array_keys($changes))
                    ->mapWithKeys(fn (string $field): array => [$field => $purchaseOrder->getOriginal($field)])
                    ->all(),
                'new_values' => $changes,
            ]);
        });
    }

    public function scopeForGa(Builder $query): Builder
    {
        return $query->whereHas('division', fn (Builder $division): Builder => $division->where('code', 'GA'));
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * PIC PO = pengguna sistem (keputusan val 5 Okt 2026). `pic_name` tetap
     * disimpan sebagai salinan nama untuk cetakan, agar PDF lama tidak berubah.
     */
    public function picUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('line_number');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PurchaseOrderAudit::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(PurchaseOrderTax::class);
    }

    public function selectedTaxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'purchase_order_taxes')
            ->using(PurchaseOrderTax::class)
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

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereDoesntHave('invoices');
    }

    public function recalculateTotals(): void
    {
        DB::transaction(function (): void {
            $subtotal = (float) $this->items()->sum('subtotal_amount');
            $itemTaxes = PurchaseOrderItemTax::query()
                ->whereHas('purchaseOrderItem', fn (Builder $query): Builder => $query->where('purchase_order_id', $this->getKey()))
                ->get();

            $this->taxes()->delete();

            foreach ($itemTaxes->groupBy('tax_id') as $taxRows) {
                $first = $taxRows->first();

                $this->taxes()->create([
                    'tax_id' => $first->tax_id,
                    'tax_code_snapshot' => $first->tax_code_snapshot,
                    'tax_name_snapshot' => $first->tax_name_snapshot,
                    'rate_snapshot' => $first->rate_snapshot,
                    'calculation_type_snapshot' => $first->calculation_type_snapshot,
                    'taxable_amount' => $taxRows->sum('taxable_amount'),
                    'tax_amount' => $taxRows->sum('tax_amount'),
                ]);
            }

            $addition = (float) $itemTaxes
                ->where('calculation_type_snapshot', 'addition')
                ->sum('tax_amount');
            $deduction = (float) $itemTaxes
                ->where('calculation_type_snapshot', 'deduction')
                ->sum('tax_amount');
            $netTax = $addition - $deduction;
            $discount = (float) ($this->discount_amount ?? 0);
            $shipping = (float) ($this->shipping_amount ?? 0);

            $this->forceFill([
                'subtotal_amount' => $subtotal,
                'tax_amount' => $netTax,
                'tax_addition_amount' => $addition,
                'tax_deduction_amount' => $deduction,
                'grand_total_amount' => $subtotal + $addition - $deduction - $discount + $shipping,
            ])->saveQuietly();
        });
    }
}
