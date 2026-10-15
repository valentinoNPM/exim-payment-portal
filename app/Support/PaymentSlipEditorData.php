<?php

namespace App\Support;

use App\Models\Buyer;
use App\Models\Customer;
use App\Models\DocumentFile;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;

final class PaymentSlipEditorData
{
    /** @return array<string, mixed> */
    public function for(string $type, ?PaymentSlip $record = null): array
    {
        $record?->loadMissing(['invoices.items.unit', 'invoices.documentFile']);

        $invoices = old('invoices');
        if (! is_array($invoices)) {
            $invoices = $record?->invoices->map(fn ($invoice): array => [
                'id' => $invoice->id,
                'discount_amount' => $invoice->discount_amount,
                'shipping_amount' => $invoice->shipping_amount,
                'buyer_id' => $invoice->buyer_id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
                'vat_invoice_number' => $invoice->vat_invoice_number,
                'tax_calculation_mode' => $invoice->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY,
                'ppn_tax_id' => $invoice->ppn_tax_id,
                'pph_tax_id' => $invoice->pph_tax_id,
                'document_file_id' => $invoice->document_file_id,
                'document_name' => $invoice->documentFile?->original_name,
                'document_url' => $invoice->documentFile ? route('document-files.view', $invoice->documentFile) : null,
                'items' => $invoice->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'item_name' => $item->item_name,
                    'quantity' => $item->quantity,
                    'unit_id' => $item->unit_id,
                    'unit_price_amount' => $item->unit_price_amount,
                    'source_supplier_name' => $item->source_supplier_name,
                    'vat_invoice_number' => $item->vat_invoice_number,
                    'ppn_tax_id' => $item->ppn_tax_id,
                    'pph_tax_id' => $item->pph_tax_id,
                    'tax_addition_amount' => $item->tax_addition_amount,
                    'tax_deduction_amount' => $item->tax_deduction_amount,
                ])->values()->all(),
            ])->values()->all() ?? [];
        }

        if ($invoices === []) {
            $invoices = [[
                'invoice_date' => today()->format('Y-m-d'),
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'items' => [['quantity' => 1]],
            ]];
        }

        // Rebuild trusted PDF metadata after validation redirects; uploaded file inputs cannot be repopulated.
        $allowedDocuments = array_merge(
            session('payment_slip_editor_document_ids', []),
            $record?->invoices->pluck('document_file_id')->filter()->all() ?? [],
        );
        $documents = DocumentFile::query()->whereIn('id', $allowedDocuments)->get()->keyBy('id');
        foreach ($invoices as &$invoice) {
            if (! is_array($invoice)) {
                $invoice = [];
            }
            $document = $documents->get($invoice['document_file_id'] ?? null);
            $invoice['document_name'] = $document?->original_name;
            $invoice['document_url'] = $document ? route('document-files.view', $document) : null;
        }
        unset($invoice);
        $selectedTaxIds = $record?->invoices->flatMap(fn ($invoice) => [
            $invoice->ppn_tax_id, $invoice->pph_tax_id,
            ...$invoice->items->pluck('ppn_tax_id')->all(), ...$invoice->items->pluck('pph_tax_id')->all(),
        ])->filter()->unique()->all() ?? [];

        $suppliers = Supplier::query()->orderBy('name')->get(['id', 'code', 'name']);
        $customers = Customer::query()->orderBy('name')->get(['id', 'code', 'name']);
        $buyers = Buyer::query()->orderBy('name')->get(['id', 'code', 'name']);
        $units = Unit::query()->orderBy('name')->get(['id', 'name']);

        return [
            'record' => $record,
            'isEdit' => $record !== null,
            'type' => $type,
            'invoices' => array_values($invoices),
            'suppliers' => $suppliers,
            'customers' => $customers,
            'buyers' => $buyers,
            'units' => $units,
            'additionTaxes' => Tax::query()->where('calculation_type', 'addition')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $selectedTaxIds))->orderBy('name')->get(['id', 'name', 'rate']),
            'deductionTaxes' => Tax::query()->where('calculation_type', 'deduction')->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $selectedTaxIds))->orderBy('name')->get(['id', 'name', 'rate']),
        ];
    }
}
