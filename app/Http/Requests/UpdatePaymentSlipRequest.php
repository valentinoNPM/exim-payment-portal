<?php

namespace App\Http\Requests;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Http\Requests\Concerns\PaymentSlipEditorMessages;
use App\Models\PaymentSlip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentSlipRequest extends FormRequest
{
    use PaymentSlipEditorMessages;

    public function authorize(): bool
    {
        $paymentSlip = $this->route('paymentSlip');

        return $paymentSlip instanceof PaymentSlip
            && $paymentSlip->status === 'draft'
            && PaymentSlipResource::canEdit($paymentSlip);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var PaymentSlip $paymentSlip */
        $paymentSlip = $this->route('paymentSlip');
        $general = $paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL;

        if ($paymentSlip->hasPurchaseOrderSource()) {
            return [
                'transaction_description' => ['required', 'string', 'max:255'],
                'submit_after_save' => ['sometimes', 'boolean'],
                'supplier_id' => ['prohibited'], 'currency' => ['prohibited'], 'customer_id' => ['prohibited'],
                'invoices' => ['required', 'array', 'size:1'],
                'invoices.*' => ['required', 'array:id,invoice_number,invoice_date,vat_invoice_number,document_pdf'],
                'invoices.*.id' => ['required', 'integer'],
                'invoices.*.invoice_number' => ['required', 'string', 'max:255'],
                'invoices.*.invoice_date' => ['required', 'date'],
                'invoices.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
                'invoices.*.document_pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:102400'],
            ];
        }

        $rules = [
            'currency' => [$general ? 'required' : 'nullable', Rule::in(PaymentSlip::CURRENCIES)],
            'transaction_description' => [$general ? 'required' : 'nullable', 'string', 'max:255'],
            'invoice_receipt_number' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'buyer_id' => [$paymentSlip->transaction_type === PaymentSlip::TYPE_EXPORT && ! $paymentSlip->usesItemizedTaxes() ? 'required' : 'nullable', 'integer', 'exists:buyers,id'],
            'submit_after_save' => ['sometimes', 'boolean'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['required', 'array'],
            'invoices.*.id' => ['nullable', 'integer'],
            'invoices.*.purchase_order_id' => ['prohibited'],
            'invoices.*.discount_amount' => [$general ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'lt:10000000000000000'],
            'invoices.*.shipping_amount' => [$general ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'lt:10000000000000000'],
            'invoices.*.buyer_id' => [$paymentSlip->transaction_type === PaymentSlip::TYPE_IMPORT || (! $general && $paymentSlip->usesItemizedTaxes()) ? 'required' : 'nullable', 'integer', 'exists:buyers,id'],
            'invoices.*.invoice_number' => ['required', 'string', 'max:255'],
            'invoices.*.invoice_date' => ['required', 'date'],
            'invoices.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
            'invoices.*.tax_calculation_mode' => ['required', Rule::in([PaymentSlip::TAX_MODE_ITEMIZED, PaymentSlip::TAX_MODE_INVOICE_LEGACY])],
            'invoices.*.ppn_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'addition')->where('is_active', true))],
            'invoices.*.pph_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'deduction')->where('is_active', true))],
            'invoices.*.document_pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:102400'],
            'invoices.*.document_file_id' => ['nullable', 'integer', 'exists:document_files,id'],
            'invoices.*.items' => ['required', 'array', 'min:1'],
            'invoices.*.items.*' => ['required', 'array'],
            'invoices.*.items.*.id' => ['nullable', 'integer'],
            'invoices.*.items.*.item_name' => ['required', 'string', 'max:255'],
            'invoices.*.items.*.quantity' => ['required', 'numeric'],
            'invoices.*.items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'invoices.*.items.*.unit_price_amount' => ['required', 'numeric'],
            'invoices.*.items.*.source_supplier_name' => ['nullable', 'string', 'max:255'],
            'invoices.*.items.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
            'invoices.*.items.*.ppn_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'addition')->where('is_active', true))],
            'invoices.*.items.*.pph_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'deduction')->where('is_active', true))],
        ];

        $storedInvoices = $paymentSlip->invoices()->with('items')->get()->keyBy('id');
        $taxRule = static fn (string $type, ?int $currentId): array => ['nullable', 'integer', Rule::exists('taxes', 'id')
            ->where(fn ($query) => $query->where('calculation_type', $type)->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $currentId)))];
        foreach ((array) $this->input('invoices', []) as $invoiceKey => $invoice) {
            if (! is_array($invoice)) {
                continue;
            }
            $storedInvoice = $storedInvoices->get($invoice['id'] ?? null);
            foreach (['ppn_tax_id' => 'addition', 'pph_tax_id' => 'deduction'] as $field => $type) {
                $rules["invoices.{$invoiceKey}.{$field}"] = $taxRule($type, $storedInvoice?->{$field});
            }
            foreach ((array) ($invoice['items'] ?? []) as $itemKey => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $storedItem = $storedInvoice?->items->firstWhere('id', $item['id'] ?? null);
                foreach (['ppn_tax_id' => 'addition', 'pph_tax_id' => 'deduction'] as $field => $type) {
                    $rules["invoices.{$invoiceKey}.items.{$itemKey}.{$field}"] = $taxRule($type, $storedItem?->{$field});
                }
                if ($general && blank($item['id'] ?? null)) {
                    $rules["invoices.{$invoiceKey}.items.{$itemKey}.unit_id"] = ['required', 'integer', 'exists:units,id'];
                }
            }
        }

        return $rules;
    }
}
