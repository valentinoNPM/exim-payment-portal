<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTax extends Pivot
{
    protected $table = 'invoice_taxes';
    
    protected $primaryKey = 'id';
    
    public $incrementing = true;

    protected $fillable = [
        'invoice_id',
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

    protected static function booted()
    {
        static::creating(function ($pivot) {
            $tax = Tax::find($pivot->tax_id);
            $invoice = Invoice::find($pivot->invoice_id);
            if ($tax && $invoice) {
                $pivot->tax_code_snapshot = $tax->code;
                $pivot->tax_name_snapshot = $tax->name;
                $pivot->rate_snapshot = $tax->rate;
                $pivot->calculation_type_snapshot = $tax->calculation_type;
                $pivot->taxable_amount = $invoice->subtotal_amount;
                $pivot->tax_amount = $invoice->subtotal_amount * ($tax->rate / 100);
            }
        });

        static::saved(function ($pivot) {
            if ($pivot->invoice) {
                $pivot->invoice->recalculateTaxes();
            }
        });

        static::deleted(function ($pivot) {
            if ($pivot->invoice) {
                $pivot->invoice->recalculateTaxes();
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
