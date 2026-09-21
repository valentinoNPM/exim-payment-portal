<?php

namespace App\Models;

use App\Services\InvoiceAmountCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $table = 'invoice_items';

    protected $fillable = [
        'invoice_id',
        'line_number',
        'item_name',
        'source_supplier_name',
        'vat_invoice_number',
        'quantity',
        'unit_price_amount',
        'subtotal_amount',
        'coa_id',
        'coa_code_snapshot',
        'coa_name_snapshot',
        'tax_addition_amount',
        'ppn_tax_id',
        'tax_deduction_amount',
        'pph_tax_id',
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
            $item->setCalculatedAmounts();

            if (empty($item->line_number)) {
                $maxLine = static::where('invoice_id', $item->invoice_id)->max('line_number');
                $item->line_number = $maxLine ? $maxLine + 1 : 1;
            }
        });

        static::updating(function ($item) {
            if ($item->isDirty(['quantity', 'unit_price_amount', 'ppn_tax_id', 'pph_tax_id', 'tax_addition_amount', 'tax_deduction_amount'])) {
                $item->setCalculatedAmounts();
            }
        });

        static::saved(function ($item) {
            if ($item->invoice && ($item->wasRecentlyCreated || $item->wasChanged(['quantity', 'unit_price_amount', 'subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'net_amount']))) {
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

    public function ppnTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'ppn_tax_id');
    }

    public function pphTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'pph_tax_id');
    }

    private function setCalculatedAmounts(): void
    {
        $this->subtotal_amount = round((float) $this->quantity * (float) $this->unit_price_amount, 2);
        $ppn = $this->taxFor('ppn_tax_id', 'addition');
        $pph = $this->taxFor('pph_tax_id', 'deduction');
        $amounts = InvoiceAmountCalculator::calculate(
            (float) $this->subtotal_amount,
            $ppn ? (float) $ppn->rate : null,
            $pph ? (float) $pph->rate : null,
        );

        $this->tax_addition_amount = $ppn
            ? $amounts['tax_addition']
            : ($this->isDirty('ppn_tax_id') ? 0 : round((float) ($this->tax_addition_amount ?? 0), 2));
        $this->tax_deduction_amount = $pph
            ? $amounts['tax_deduction']
            : ($this->isDirty('pph_tax_id') ? 0 : round((float) ($this->tax_deduction_amount ?? 0), 2));
        $this->net_amount = $this->subtotal_amount + $this->tax_addition_amount - $this->tax_deduction_amount;
    }

    private function taxFor(string $field, string $type): ?Tax
    {
        if (! $this->{$field}) {
            return null;
        }

        $tax = Tax::find($this->{$field});
        if (! $tax || $tax->calculation_type !== $type || ($this->isDirty($field) && ! $tax->is_active)) {
            throw ValidationException::withMessages([$field => "Selected {$type} tax is invalid."]);
        }

        return $tax;
    }
}
