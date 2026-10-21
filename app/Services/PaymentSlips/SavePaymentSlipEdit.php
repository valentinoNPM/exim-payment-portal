<?php

namespace App\Services\PaymentSlips;

use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Tax;
use App\Services\InvoiceAmountCalculator;
use App\Support\MoneyRounding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SavePaymentSlipEdit
{
    /** @var list<string> */
    private array $newUploadPaths = [];

    /** @param array<string, mixed> $data */
    public function execute(PaymentSlip $paymentSlip, array $data): PaymentSlip
    {
        $this->newUploadPaths = [];
        try {
            return DB::transaction(function () use ($paymentSlip, $data): PaymentSlip {
                $paymentSlip = PaymentSlip::query()->lockForUpdate()->findOrFail($paymentSlip->getKey());
                abort_unless($paymentSlip->status === 'draft', 403);

                if ($paymentSlip->hasPurchaseOrderSource()) {
                    return $this->saveLinkedMetadata($paymentSlip, $data);
                }
                foreach ($data['invoices'] as $invoiceData) {
                    if (filled($invoiceData['purchase_order_id'] ?? null)) {
                        throw ValidationException::withMessages(['invoices' => 'Referensi PO hanya boleh dibuat melalui penerbitan dari PO.']);
                    }
                }

                $slipData = $paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL
                    ? GeneralPaymentSlipData::prepareEdit(Arr::only($data, ['currency', 'transaction_description', 'supplier_id', 'customer_id', 'approval_incharge', 'approval_passed_1', 'approval_passed_2', 'approval_passed_3', 'approval_director']))
                    : EximPaymentSlipData::prepareEdit(Arr::only($data, ['invoice_receipt_number', 'supplier_id', 'buyer_id']), $paymentSlip);
                $paymentSlip->update($slipData);
                $currencyChanged = $paymentSlip->wasChanged('currency');

                $keptInvoiceIds = [];
                foreach ($data['invoices'] as $invoiceKey => $invoiceData) {
                    $invoice = $this->invoiceFor($paymentSlip, $invoiceData['id'] ?? null);
                    if ($paymentSlip->transaction_type !== PaymentSlip::TYPE_GENERAL
                        && $invoiceData['tax_calculation_mode'] === PaymentSlip::TAX_MODE_INVOICE_LEGACY
                        && (! $invoice->exists || $invoice->usesItemizedTaxes())
                        && collect($invoiceData['items'])->contains(fn (array $item): bool => filled($item['vat_invoice_number'] ?? null)
                            || ($paymentSlip->transaction_type === PaymentSlip::TYPE_IMPORT && filled($item['source_supplier_name'] ?? null)))) {
                        throw ValidationException::withMessages([
                            "invoices.{$invoiceKey}.tax_calculation_mode" => 'Invoice dengan supplier pendukung atau VAT per item harus memakai pajak per item agar detail ERP tidak hilang.',
                        ]);
                    }
                    if ($paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL) {
                        // Historical invoice-level taxes must survive an ordinary edit.
                        $invoiceData['tax_calculation_mode'] = $invoice->exists
                            ? ($invoice->usesItemizedTaxes() ? PaymentSlip::TAX_MODE_ITEMIZED : PaymentSlip::TAX_MODE_INVOICE_LEGACY)
                            : PaymentSlip::TAX_MODE_ITEMIZED;
                    }
                    $invoiceData += ['ppn_tax_id' => null, 'pph_tax_id' => null];
                    $invoice->fill(Arr::only($invoiceData, [
                        'buyer_id',
                        'invoice_number',
                        'invoice_date',
                        'vat_invoice_number',
                        'tax_calculation_mode',
                        'ppn_tax_id',
                        'pph_tax_id',
                    ]));
                    $invoice->payment_slip_id = $paymentSlip->getKey();

                    if (($invoiceData['document_pdf'] ?? null) instanceof UploadedFile) {
                        $invoice->document_file_id = $this->storeDocument($invoiceData['document_pdf'])->getKey();
                    } elseif (filled($invoiceData['document_file_id'] ?? null)) {
                        $documentId = (int) $invoiceData['document_file_id'];
                        $allowed = $invoice->exists && (int) $invoice->document_file_id === $documentId;
                        $allowed = $allowed || in_array($documentId, session('payment_slip_editor_document_ids', []), true);
                        abort_unless($allowed, 403);
                        $invoice->document_file_id = $documentId;
                    }

                    $invoice->save();
                    $keptInvoiceIds[] = $invoice->getKey();
                    $adjustments = [];
                    if ($paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL) {
                        $adjustments = [
                            'discount_amount' => $invoiceData['discount_amount'] ?? $invoice->discount_amount ?? 0,
                            'shipping_amount' => $invoiceData['shipping_amount'] ?? $invoice->shipping_amount ?? 0,
                        ];
                        // Item events recalculate intermediate totals; apply adjustments once after all rows.
                        $invoice->forceFill(['discount_amount' => 0, 'shipping_amount' => 0])->saveQuietly();
                    }
                    $this->syncItems($invoice, $invoiceData['items'], $currencyChanged);
                    if ($paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL) {
                        $invoice->fill($adjustments);
                    }
                    $invoice->recalculateTotals();
                }

                $paymentSlip->invoices()->whereNotIn('id', $keptInvoiceIds)->get()->each->delete();
                $paymentSlip->recalculateTotals();

                return $paymentSlip->fresh(['invoices.items']);
            });
        } catch (\Throwable $exception) {
            $this->discardRolledBackUploads();
            throw $exception;
        }
    }

    public function discardRolledBackUploads(): void
    {
        foreach ($this->newUploadPaths as $path) {
            if (! DocumentFile::query()->where('disk', 'local')->where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function invoiceFor(PaymentSlip $paymentSlip, mixed $invoiceId): Invoice
    {
        if (blank($invoiceId)) {
            return new Invoice;
        }

        return $paymentSlip->invoices()->findOrFail((int) $invoiceId);
    }

    private function saveLinkedMetadata(PaymentSlip $slip, array $data): PaymentSlip
    {
        if (array_diff(array_keys($data), ['transaction_description', 'approval_incharge', 'approval_passed_1', 'approval_passed_2', 'approval_passed_3', 'approval_director', 'invoices', 'submit_after_save']) !== []) {
            throw ValidationException::withMessages(['payment_slip' => 'Data sumber PO tidak dapat diubah.']);
        }
        $stored = $slip->invoices()->get()->keyBy('id');
        $rows = $data['invoices'] ?? [];
        if (count($rows) !== $stored->count() || count(array_unique(array_column($rows, 'id'))) !== $stored->count()) {
            throw ValidationException::withMessages(['invoices' => 'Invoice sumber PO tidak dapat ditambah/dihapus.']);
        }
        foreach ($rows as $row) {
            $invoice = $stored->get($row['id'] ?? null);
            if (! $invoice || array_diff(array_keys($row), ['id', 'invoice_number', 'invoice_date', 'vat_invoice_number', 'document_pdf']) !== []) {
                throw ValidationException::withMessages(['invoices' => 'Hanya identitas invoice supplier dan lampiran yang dapat diubah.']);
            }
            $invoice->fill(Arr::only($row, ['invoice_number', 'invoice_date', 'vat_invoice_number']));
            if (($row['document_pdf'] ?? null) instanceof UploadedFile) {
                $invoice->document_file_id = $this->storeDocument($row['document_pdf'])->id;
            }
            $invoice->save();
        }
        $slip->update(Arr::only($data, ['transaction_description', 'approval_incharge', 'approval_passed_1', 'approval_passed_2', 'approval_passed_3', 'approval_director']));

        return $slip->fresh('invoices.items');
    }

    /** @param array<int|string, array<string, mixed>> $items */
    private function syncItems(Invoice $invoice, array $items, bool $currencyChanged): void
    {
        $keptItemIds = [];
        foreach (array_values($items) as $index => $itemData) {
            $item = blank($itemData['id'] ?? null)
                ? new InvoiceItem
                : $invoice->items()->findOrFail((int) $itemData['id']);
            $item->fill(Arr::only($itemData, [
                'item_name',
                'quantity',
                'unit_id',
                'unit_price_amount',
                'source_supplier_name',
                'vat_invoice_number',
                'ppn_tax_id',
                'pph_tax_id',
            ]));
            $item->invoice_id = $invoice->getKey();
            $item->ppn_tax_id = $invoice->usesItemizedTaxes() ? ($itemData['ppn_tax_id'] ?? null) : null;
            $item->pph_tax_id = $invoice->usesItemizedTaxes() ? ($itemData['pph_tax_id'] ?? null) : null;
            $recalculate = ! $item->exists || $currencyChanged || $item->isDirty(['quantity', 'unit_price_amount', 'ppn_tax_id', 'pph_tax_id']);
            $subtotal = (float) $item->subtotal_amount;
            if ($recalculate) {
                $raw = (float) $item->quantity * (float) $item->unit_price_amount;
                $subtotal = $invoice->paymentSlip->transaction_type === PaymentSlip::TYPE_GENERAL
                    ? MoneyRounding::round($raw, $invoice->paymentSlip->currency)
                    : round($raw, 2);
                $item->subtotal_amount = $subtotal;
            }
            // Currency changes also require fresh rounding when quantity and tax IDs are unchanged.
            $amounts = InvoiceAmountCalculator::calculateForCurrency(
                $subtotal,
                $item->ppn_tax_id ? Tax::find($item->ppn_tax_id)?->rate : null,
                $item->pph_tax_id ? Tax::find($item->pph_tax_id)?->rate : null,
                $invoice->paymentSlip->currency ?? PaymentSlip::CURRENCY_IDR,
            );
            if ($recalculate && ($item->ppn_tax_id || ! $invoice->usesItemizedTaxes() || $item->isDirty('ppn_tax_id'))) {
                $item->tax_addition_amount = $amounts['tax_addition'];
            }
            if ($recalculate && ($item->pph_tax_id || ! $invoice->usesItemizedTaxes() || $item->isDirty('pph_tax_id'))) {
                $item->tax_deduction_amount = $amounts['tax_deduction'];
            }
            $item->line_number = $index + 1;
            if ($recalculate) {
                $item->net_amount = $subtotal + (float) $item->tax_addition_amount - (float) $item->tax_deduction_amount;
            }
            $item->save();
            $keptItemIds[] = $item->getKey();
        }

        $invoice->items()->whereNotIn('id', $keptItemIds)->get()->each->delete();
    }

    private function storeDocument(UploadedFile $file): DocumentFile
    {
        $path = $file->store('invoice-uploads', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['document_pdf' => 'PDF gagal disimpan. Silakan coba lagi.']);
        }
        $this->newUploadPaths[] = $path;
        $fullPath = Storage::disk('local')->path($path);

        return DocumentFile::create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => 'application/pdf',
            'size_bytes' => $file->getSize(),
            'checksum' => md5_file($fullPath),
            'uploaded_at' => now(),
        ]);
    }
}
