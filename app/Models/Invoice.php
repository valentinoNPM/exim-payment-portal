<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_slip_id',
        'invoice_number',
        'invoice_date',
        'subtotal_amount',
        'tax_addition_amount',
        'tax_deduction_amount',
        'grand_total_amount',
        'document_file_id',
        'coa_id',
        'coa_code_snapshot',
        'coa_name_snapshot',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'subtotal_amount' => 'decimal:2',
        'tax_addition_amount' => 'decimal:2',
        'tax_deduction_amount' => 'decimal:2',
        'grand_total_amount' => 'decimal:2',
    ];

    public function paymentSlip(): BelongsTo
    {
        return $this->belongsTo(PaymentSlip::class);
    }

    public function documentFile(): BelongsTo
    {
        return $this->belongsTo(DocumentFile::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class, 'invoice_id');
    }

    public function selectedTaxes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'invoice_taxes', 'invoice_id', 'tax_id')
            ->using(InvoiceTax::class)
            ->withPivot(['tax_code_snapshot', 'tax_name_snapshot', 'rate_snapshot', 'calculation_type_snapshot', 'taxable_amount', 'tax_amount']);
    }

    protected static function booted()
    {
        static::saving(function ($invoice) {
            if ($invoice->isDirty('coa_id')) {
                if ($invoice->coa_id) {
                    $coa = ChartOfAccount::find($invoice->coa_id);
                    $invoice->coa_code_snapshot = $coa?->code;
                    $invoice->coa_name_snapshot = $coa?->name;
                } else {
                    $invoice->coa_code_snapshot = null;
                    $invoice->coa_name_snapshot = null;
                }
            }
        });

        static::saved(function ($invoice) {
            if ($invoice->paymentSlip) {
                $invoice->paymentSlip->recalculateTotals();
            }
        });

        static::deleted(function ($invoice) {
            if ($invoice->paymentSlip) {
                $invoice->paymentSlip->recalculateTotals();
            }
        });
    }

    public function recalculateTaxes()
    {
        $this->tax_addition_amount = $this->taxes()
            ->where('calculation_type_snapshot', 'addition')
            ->sum('tax_amount') ?? 0;

        $this->tax_deduction_amount = $this->taxes()
            ->where('calculation_type_snapshot', 'deduction')
            ->sum('tax_amount') ?? 0;

        $this->grand_total_amount = $this->subtotal_amount + $this->tax_addition_amount - $this->tax_deduction_amount;
        $this->saveQuietly();

        if ($this->paymentSlip) {
            $this->paymentSlip->recalculateTotals();
        }
    }

    public function recalculateTotals()
    {

        foreach ($this->taxes()->get() as $pivotTax) {
            $pivotTax->taxable_amount = $this->subtotal_amount;
            $pivotTax->tax_amount = $this->subtotal_amount * ($pivotTax->rate_snapshot / 100);
            $pivotTax->saveQuietly();
        }

        $this->tax_addition_amount = $this->taxes()
            ->where('calculation_type_snapshot', 'addition')
            ->sum('tax_amount') ?? 0;

        $this->tax_deduction_amount = $this->taxes()
            ->where('calculation_type_snapshot', 'deduction')
            ->sum('tax_amount') ?? 0;

        $this->grand_total_amount = $this->subtotal_amount + $this->tax_addition_amount - $this->tax_deduction_amount;
        $this->saveQuietly();

        if ($this->paymentSlip) {
            $this->paymentSlip->recalculateTotals();
        }
    }
}
