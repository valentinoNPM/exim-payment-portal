<?php

namespace App\Models;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'invoice_items';

    protected $fillable = [
        'invoice_id',
        'line_number',
        'item_name',
        'quantity',
        'unit_price_amount',
        'subtotal_amount',
        'coa_id',
        'coa_code_snapshot',
        'coa_name_snapshot',
        'tax_addition_amount',
        'tax_deduction_amount',
        'net_amount',
    ];

    protected static function booted()
    {
        static::saving(function (InvoiceItem $item): void {
            if ($item->isDirty(['coa_id', 'coa_code_snapshot', 'coa_name_snapshot'])) {
                $user = auth()->user();
                $status = PaymentSlip::query()
                    ->whereKey($item->invoice()->value('payment_slip_id'))
                    ->value('status');
                if (! $user?->hasRole('checker') || $status !== 'submitted') {
                    throw new AuthorizationException('Only Accounting may change item COA during verification.');
                }
                if ($item->isDirty('coa_id')) {
                    $coa = $item->coa_id ? ChartOfAccount::findOrFail($item->coa_id) : null;
                    $item->coa_code_snapshot = $coa?->code;
                    $item->coa_name_snapshot = $coa?->name;
                } else {
                    // Snapshots are derived from a selection, never client-supplied text.
                    $item->coa_code_snapshot = $item->getRawOriginal('coa_code_snapshot');
                    $item->coa_name_snapshot = $item->getRawOriginal('coa_name_snapshot');
                }
            }
        });
        static::creating(function ($item) {
            $item->subtotal_amount = $item->quantity * $item->unit_price_amount;
            $item->net_amount = $item->subtotal_amount;

            if (empty($item->line_number)) {
                $maxLine = static::where('invoice_id', $item->invoice_id)->max('line_number');
                $item->line_number = $maxLine ? $maxLine + 1 : 1;
            }
        });

        static::updating(function ($item) {
            if ($item->isDirty(['quantity', 'unit_price_amount'])) {
                $item->subtotal_amount = $item->quantity * $item->unit_price_amount;
                $item->net_amount = $item->subtotal_amount;
            }
        });

        static::saved(function ($item) {
            if ($item->invoice && ($item->wasRecentlyCreated || $item->wasChanged(['quantity', 'unit_price_amount', 'subtotal_amount']))) {
                $item->invoice->recalculateTotals();
            }
        });

        static::deleted(function ($item) {
            if ($item->invoice) {
                $item->invoice->recalculateTotals();
            }
        });
    }

    protected $casts = [
        'line_number' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price_amount' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'tax_addition_amount' => 'decimal:2',
        'tax_deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }
}
