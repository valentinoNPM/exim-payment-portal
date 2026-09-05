<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_slip_id',
        'invoice_number',
        'invoice_date',
        'vat_invoice_number',
        'subtotal_amount',
        'tax_addition_amount',
        'tax_deduction_amount',
        'grand_total_amount',
        'document_file_id',
        'ppn_tax_id',
        'pph_tax_id',
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

    public function ppnTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'ppn_tax_id');
    }

    public function pphTax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'pph_tax_id');
    }

    // Keep legacy relations for backward compatibility
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class, 'invoice_id');
    }

    public function selectedTaxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'invoice_taxes', 'invoice_id', 'tax_id')
            ->using(InvoiceTax::class)
            ->withPivot(['tax_code_snapshot', 'tax_name_snapshot', 'rate_snapshot', 'calculation_type_snapshot', 'taxable_amount', 'tax_amount']);
    }

    protected static function booted()
    {
        static::saving(function ($invoice) {
            // Recalculate taxes using Opsi B (direct FK ppn_tax_id / pph_tax_id)
            if ($invoice->isDirty(['ppn_tax_id', 'pph_tax_id', 'subtotal_amount'])) {
                $subtotal = (float) $invoice->subtotal_amount;

                $ppnAmount = 0;
                if ($invoice->ppn_tax_id) {
                    $ppnTax = Tax::find($invoice->ppn_tax_id);
                    $ppnAmount = $ppnTax ? $subtotal * ($ppnTax->rate / 100) : 0;
                }

                $pphAmount = 0;
                if ($invoice->pph_tax_id) {
                    $pphTax = Tax::find($invoice->pph_tax_id);
                    $pphAmount = $pphTax ? $subtotal * ($pphTax->rate / 100) : 0;
                }

                $invoice->tax_addition_amount = $ppnAmount;
                $invoice->tax_deduction_amount = $pphAmount;
                $invoice->grand_total_amount = $subtotal + $ppnAmount - $pphAmount;
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

    public function recalculateTotals()
    {
        $subtotal = (float) $this->subtotal_amount;

        $ppnAmount = 0;
        if ($this->ppn_tax_id) {
            $ppnTax = Tax::find($this->ppn_tax_id);
            $ppnAmount = $ppnTax ? $subtotal * ($ppnTax->rate / 100) : 0;
        }

        $pphAmount = 0;
        if ($this->pph_tax_id) {
            $pphTax = Tax::find($this->pph_tax_id);
            $pphAmount = $pphTax ? $subtotal * ($pphTax->rate / 100) : 0;
        }

        $this->tax_addition_amount = $ppnAmount;
        $this->tax_deduction_amount = $pphAmount;
        $this->grand_total_amount = $subtotal + $ppnAmount - $pphAmount;
        $this->saveQuietly();

        if ($this->paymentSlip) {
            $this->paymentSlip->recalculateTotals();
        }
    }
}
