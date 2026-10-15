<?php

namespace App\Models;

use App\Services\InvoiceAmountCalculator;
use App\Services\PaymentSlips\GeneralInvoiceTotal;
use App\Support\MoneyRounding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_slip_id',
        'purchase_order_id',
        'discount_amount',
        'shipping_amount',
        'buyer_id',
        'invoice_number',
        'invoice_date',
        'vat_invoice_number',
        'tax_calculation_mode',
        'subtotal_amount',
        'tax_addition_amount',
        'tax_deduction_amount',
        'grand_total_amount',
        'document_file_id',
        'ppn_tax_id',
        'pph_tax_id',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
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
        static::updating(function (Invoice $invoice): void {
            if ($invoice->getRawOriginal('purchase_order_id') && $invoice->isDirty(['purchase_order_id', 'payment_slip_id', 'tax_calculation_mode', 'ppn_tax_id', 'pph_tax_id', 'discount_amount', 'shipping_amount'])) {
                throw ValidationException::withMessages(['invoices' => 'Sumber PO pada invoice terkunci.']);
            }
        });
        static::deleting(function (Invoice $invoice): void {
            if ($invoice->purchase_order_id) {
                throw ValidationException::withMessages(['invoices' => 'Invoice dari PO tidak boleh dihapus.']);
            }
        });
        static::creating(function (Invoice $invoice): void {
            $parent = $invoice->paymentSlip ?? PaymentSlip::query()->find($invoice->payment_slip_id);
            if ($parent?->hasPurchaseOrderSource()) {
                throw ValidationException::withMessages(['invoices' => 'Tidak dapat menambah invoice pada slip sumber PO.']);
            }
            if ($parent?->transaction_type === PaymentSlip::TYPE_GENERAL) {
                $invoice->tax_calculation_mode = PaymentSlip::TAX_MODE_ITEMIZED;

                return;
            }
            $invoice->tax_calculation_mode ??= $parent?->usesItemizedTaxes()
                ? PaymentSlip::TAX_MODE_ITEMIZED
                : PaymentSlip::TAX_MODE_INVOICE_LEGACY;
        });

        static::saving(function ($invoice) {
            if ($invoice->buyer_id === null && $invoice->paymentSlip?->buyer_id !== null) {
                $invoice->buyer_id = $invoice->paymentSlip->buyer_id;
            }

            if ($invoice->isDirty('tax_calculation_mode') && $invoice->usesItemizedTaxes()) {
                $invoice->ppn_tax_id = null;
                $invoice->pph_tax_id = null;
            }

            if ($invoice->usesItemizedTaxes()) {
                $invoice->subtotal_amount = $invoice->items()->sum('subtotal_amount');
                $invoice->tax_addition_amount = $invoice->items()->sum('tax_addition_amount');
                $invoice->tax_deduction_amount = $invoice->items()->sum('tax_deduction_amount');
                $invoice->grand_total_amount = $invoice->subtotal_amount + $invoice->tax_addition_amount - $invoice->tax_deduction_amount;
                $invoice->applyGeneralAdjustments();

                return;
            }

            // Recalculate taxes using Opsi B (direct FK ppn_tax_id / pph_tax_id)
            if ($invoice->isDirty(['ppn_tax_id', 'pph_tax_id', 'subtotal_amount'])) {
                $subtotal = (float) $invoice->subtotal_amount;

                $amounts = InvoiceAmountCalculator::calculateForCurrency(
                    $subtotal,
                    Tax::find($invoice->ppn_tax_id)?->rate,
                    Tax::find($invoice->pph_tax_id)?->rate,
                    $invoice->calculationCurrency(),
                );

                $invoice->tax_addition_amount = $amounts['tax_addition'];
                $invoice->tax_deduction_amount = $amounts['tax_deduction'];
                $invoice->grand_total_amount = $amounts['grand_total'];
            }
            $invoice->applyGeneralAdjustments();
        });

        static::saved(function ($invoice) {
            if ($invoice->wasChanged('tax_calculation_mode') && ! $invoice->usesItemizedTaxes()) {
                $invoice->items()->update([
                    'ppn_tax_id' => null,
                    'pph_tax_id' => null,
                    'tax_addition_amount' => 0,
                    'tax_deduction_amount' => 0,
                    'net_amount' => DB::raw('subtotal_amount'),
                ]);
            }

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
        $this->subtotal_amount = $this->items()->sum('subtotal_amount');

        if ($this->usesItemizedTaxes()) {
            $this->tax_addition_amount = $this->items()->sum('tax_addition_amount');
            $this->tax_deduction_amount = $this->items()->sum('tax_deduction_amount');
            $this->grand_total_amount = $this->subtotal_amount + $this->tax_addition_amount - $this->tax_deduction_amount;
            $this->applyGeneralAdjustments();
            $this->saveQuietly();

            if ($this->paymentSlip) {
                $this->paymentSlip->recalculateTotals();
            }

            return;
        }

        $amounts = InvoiceAmountCalculator::calculateForCurrency(
            (float) $this->subtotal_amount,
            Tax::find($this->ppn_tax_id)?->rate,
            Tax::find($this->pph_tax_id)?->rate,
            $this->calculationCurrency(),
        );

        $this->tax_addition_amount = $amounts['tax_addition'];
        $this->tax_deduction_amount = $amounts['tax_deduction'];
        $this->grand_total_amount = $amounts['grand_total'];
        $this->applyGeneralAdjustments();
        $this->saveQuietly();

        if ($this->paymentSlip) {
            $this->paymentSlip->recalculateTotals();
        }
    }

    public function usesItemizedTaxes(): bool
    {
        if ($this->tax_calculation_mode !== null) {
            return $this->tax_calculation_mode === PaymentSlip::TAX_MODE_ITEMIZED;
        }

        return $this->paymentSlip?->usesItemizedTaxes() ?? false;
    }

    private ?array $sourceUnitNames = null;

    public function unitNameFor(InvoiceItem $item): ?string
    {
        if (! $this->purchase_order_id) {
            return $item->unit?->name;
        }
        if ($this->sourceUnitNames === null) {
            $snapshot = $this->purchaseOrder?->audits()->where('event', 'payment_slip_issued')->first()?->new_values ?? [];
            $this->sourceUnitNames = collect($snapshot['items'] ?? [])->pluck('unit_name', 'invoice_item_id')->all();
        }

        return $this->sourceUnitNames[$item->id] ?? $item->unit?->name;
    }

    private function calculationCurrency(): string
    {
        $paymentSlip = $this->paymentSlip ?? PaymentSlip::query()->find($this->payment_slip_id);

        return $paymentSlip?->transaction_type === PaymentSlip::TYPE_GENERAL
            ? ($paymentSlip->currency ?? PaymentSlip::CURRENCY_IDR)
            : PaymentSlip::CURRENCY_IDR;
    }

    private function applyGeneralAdjustments(): void
    {
        if ($this->paymentSlip?->transaction_type !== PaymentSlip::TYPE_GENERAL) {
            return;
        }
        foreach (['discount_amount', 'shipping_amount'] as $field) {
            $this->{$field} = MoneyRounding::round((float) $this->{$field}, $this->calculationCurrency());
        }
        // An invoice is first inserted before its items. Validate the total after items exist.
        if (! $this->exists) {
            return;
        }
        $this->grand_total_amount = GeneralInvoiceTotal::calculate(
            (float) $this->subtotal_amount, (float) $this->tax_addition_amount, (float) $this->tax_deduction_amount,
            (float) $this->discount_amount, (float) $this->shipping_amount, $this->calculationCurrency(),
        );
    }
}
