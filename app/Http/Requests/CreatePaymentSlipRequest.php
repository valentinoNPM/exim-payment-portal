<?php

namespace App\Http\Requests;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Http\Requests\Concerns\PaymentSlipEditorMessages;
use App\Models\PaymentSlip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentSlipRequest extends FormRequest
{
    use PaymentSlipEditorMessages;

    public function authorize(): bool
    {
        return PaymentSlipResource::canCreateType(auth()->user(), (string) $this->route('type'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = (string) $this->route('type');
        $general = $type === PaymentSlip::TYPE_GENERAL;

        return [
            'transaction_type' => ['sometimes', Rule::in($general ? [PaymentSlip::TYPE_GENERAL] : [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT])],
            'currency' => [$general ? 'required' : 'nullable', Rule::in(PaymentSlip::CURRENCIES)],
            'transaction_description' => [$general ? 'required' : 'nullable', 'string', 'max:255'],
            'invoice_receipt_number' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'buyer_id' => ['nullable', 'integer', 'exists:buyers,id'],
            'submit_after_save' => ['sometimes', 'boolean'],
            'create_another' => ['sometimes', 'boolean'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['required', 'array'],
            'invoices.*.purchase_order_id' => ['prohibited'],
            'invoices.*.discount_amount' => [$general ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'lt:10000000000000000'],
            'invoices.*.shipping_amount' => [$general ? 'nullable' : 'prohibited', 'numeric', 'min:0', 'lt:10000000000000000'],
            'invoices.*.buyer_id' => [$general ? 'nullable' : 'required', 'integer', 'exists:buyers,id'],
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
            'invoices.*.items.*.item_name' => ['required', 'string', 'max:255'],
            'invoices.*.items.*.quantity' => ['required', 'numeric'],
            'invoices.*.items.*.unit_id' => [$general ? 'required' : 'nullable', 'integer', 'exists:units,id'],
            'invoices.*.items.*.unit_price_amount' => ['required', 'numeric'],
            'invoices.*.items.*.source_supplier_name' => ['nullable', 'string', 'max:255'],
            'invoices.*.items.*.vat_invoice_number' => ['nullable', 'string', 'max:255'],
            'invoices.*.items.*.ppn_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'addition')->where('is_active', true))],
            'invoices.*.items.*.pph_tax_id' => ['nullable', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('calculation_type', 'deduction')->where('is_active', true))],
        ];
    }
}
