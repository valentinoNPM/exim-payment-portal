<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentSlip extends Model
{
    use HasFactory;

    protected $fillable = [
        'slip_number',
        'transaction_type',
        'supplier_id',
        'buyer_id',
        'status',
        'subtotal_amount',
        'tax_addition_amount',
        'tax_deduction_amount',
        'grand_total_amount',
        'created_by',
        'approved_by',
        'submitted_at',
        'verified_at',
        'approved_at',
    ];

    protected $casts = [
        'subtotal_amount' => 'decimal:2',
        'tax_addition_amount' => 'decimal:2',
        'tax_deduction_amount' => 'decimal:2',
        'grand_total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PaymentSlipAudit::class);
    }

    public function erpExportItem(): HasOne
    {
        return $this->hasOne(ErpExportItem::class);
    }

    public function recalculateTotals()
    {
        $this->subtotal_amount = $this->invoices()->sum('subtotal_amount');
        $this->tax_addition_amount = $this->invoices()->sum('tax_addition_amount');
        $this->tax_deduction_amount = $this->invoices()->sum('tax_deduction_amount');
        $this->grand_total_amount = $this->invoices()->sum('grand_total_amount');
        $this->saveQuietly();
    }
}
