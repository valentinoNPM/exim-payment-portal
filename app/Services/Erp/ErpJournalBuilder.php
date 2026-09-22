<?php

namespace App\Services\Erp;

use App\Data\ErpJournalRow;
use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Services\InvoiceAmountCalculator;
use Illuminate\Validation\ValidationException;

class ErpJournalBuilder
{
    public function __construct(private AccountResolver $resolver, private ErpJournalValidator $validator) {}

    /** @return list<ErpJournalRow> */
    public function build(PaymentSlip $slip, array $vatNumbers = []): array
    {
        $slip->loadMissing(['supplier', 'buyer', 'invoices.buyer', 'invoices.items.chartOfAccount', 'erpExportItem']);
        $this->require($slip->erpExportItem === null, 'This payment slip has already been exported.');
        $this->require($slip->status === 'approved', 'Only approved payment slips can be exported.');
        $this->require(filled($slip->supplier?->code), 'Supplier or supplier code is missing.');
        $costCenter = config('erp-export.cost_centers.'.$slip->transaction_type);
        $this->require(filled($costCenter), 'Import/Export type is invalid.');
        $this->require($slip->invoices->isNotEmpty(), 'Payment slip has no invoices.');
        $rows = [];
        foreach ($slip->invoices->sortBy('id') as $invoice) {
            $buyerName = trim((string) ($invoice->buyer?->name ?: $slip->buyer?->name));
            if ($slip->transaction_type !== PaymentSlip::TYPE_GENERAL) {
                $this->require($buyerName !== '', "Invoice {$invoice->invoice_number} has no buyer.");
            }

            if ($invoice->usesItemizedTaxes() && $slip->transaction_type !== PaymentSlip::TYPE_GENERAL) {
                array_push($rows, ...$this->buildItemizedInvoiceRows($slip, $invoice, $costCenter, $buyerName, $vatNumbers));

                continue;
            }

            $context = "Invoice {$invoice->invoice_number}";
            $this->require($invoice->items->isNotEmpty(), "{$context} has no expense items.");
            if ($slip->transaction_type === PaymentSlip::TYPE_IMPORT) {
                $this->require(
                    ! $invoice->items->contains(fn ($item): bool => filled($item->source_supplier_name) || filled($item->vat_invoice_number)),
                    "{$context}: supporting supplier or item VAT number requires item-level taxes to preserve ERP detail.",
                );
            }
            $this->require($invoice->invoice_date !== null, "{$context} has no invoice date.");
            $date = $invoice->invoice_date->format('Y-m-d');
            $descriptionContext = $slip->transaction_type === PaymentSlip::TYPE_GENERAL
                ? 'inv '.$invoice->invoice_number.'-'.trim((string) $slip->supplier->name)
                : implode(' ', array_filter([
                    $buyerName,
                    'inv '.$invoice->invoice_number,
                ])).'-'.trim((string) $slip->supplier->name);
            $chargeType = strtolower($slip->transaction_type);
            $invoiceRows = [];
            $expense = 0;
            foreach ($invoice->items->sortBy('line_number') as $item) {
                $amount = InvoiceAmountCalculator::roundMinorUnits(
                    $this->validator->money($item->getRawOriginal('subtotal_amount') ?? $item->subtotal_amount, "{$context}, item {$item->item_name}"),
                );
                $expense += $amount;
                $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'Expense', 'ledger', $this->resolver->resolve($item, $invoice), $item->item_name.' for '.$descriptionContext, $amount, null, $costCenter);
            }
            $this->require($expense > 0, "{$context} has no positive expense value.");
            $ppn = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($invoice->getRawOriginal('tax_addition_amount') ?? $invoice->tax_addition_amount, "{$context} PPN"),
            );
            $pph = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($invoice->getRawOriginal('tax_deduction_amount') ?? $invoice->tax_deduction_amount, "{$context} PPh"),
            );
            $net = $expense + $ppn - $pph;
            $storedNet = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($invoice->getRawOriginal('grand_total_amount') ?? $invoice->grand_total_amount, "{$context} net payable"),
            );
            $this->require($storedNet === $net, "{$context}: net payable is inconsistent with the rounded expense and taxes.");
            $vat = $vatNumbers[$invoice->id] ?? $invoice->vat_invoice_number ?? '';
            $this->require(is_string($vat) && mb_strlen($vat) <= 255, "{$context}: VAT Invoice No. must be text of at most 255 characters.");
            $vat = trim($vat);
            if ($ppn > 0) {
                $ppnDescription = $vat === '' ? $descriptionContext : $vat.' -'.$descriptionContext;
                $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'PPN', 'ledger', config('erp-export.accounts.ppn'), $ppnDescription, $ppn, null);
            }
            if ($pph > 0) {
                $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'PPh', 'ledger', config('erp-export.accounts.pph_23'), "PPh 23 {$chargeType} charge for {$descriptionContext}", null, $pph);
            }
            $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'Supplier', 'Supplier', $slip->supplier->code, "AP {$chargeType} charge for {$descriptionContext}", null, $net, vatInvoiceNumber: $vat === '' ? null : $vat);
            $this->validator->balance($invoiceRows, $context);
            array_push($rows, ...$invoiceRows);
        }
        $this->validator->balance($rows, 'Payment slip '.$slip->slip_number);

        return $rows;
    }

    /** @return list<ErpJournalRow> */
    private function buildItemizedInvoiceRows(PaymentSlip $slip, Invoice $invoice, string $costCenter, string $buyerName, array $vatNumbers): array
    {
        $context = "Invoice {$invoice->invoice_number}";
        $this->require($invoice->items->isNotEmpty(), "{$context} has no expense items.");
        $this->require($invoice->invoice_date !== null, "{$context} has no invoice date.");
        $date = $invoice->invoice_date->format('Y-m-d');
        $buyerInvoice = implode(' ', array_filter([
            $buyerName,
            'inv '.$invoice->invoice_number,
        ]));
        $chargeType = strtolower($slip->transaction_type);
        $mainVat = $vatNumbers[$invoice->id] ?? $invoice->vat_invoice_number ?? '';
        $this->require(is_string($mainVat) && mb_strlen($mainVat) <= 255, "{$context}: VAT Invoice No. must be text of at most 255 characters.");
        $mainVat = trim($mainVat);
        $invoiceRows = [];
        $expense = 0;
        $ppnTotal = 0;
        $pphTotal = 0;

        foreach ($invoice->items->sortBy('line_number') as $item) {
            $itemContext = "{$context}, item {$item->item_name}";
            $amount = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($item->getRawOriginal('subtotal_amount') ?? $item->subtotal_amount, $itemContext),
            );
            $ppn = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($item->getRawOriginal('tax_addition_amount') ?? $item->tax_addition_amount, "{$itemContext} PPN"),
            );
            $itemPph = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($item->getRawOriginal('tax_deduction_amount') ?? $item->tax_deduction_amount, "{$itemContext} PPh"),
            );
            $itemNet = $amount + $ppn - $itemPph;
            $storedItemNet = InvoiceAmountCalculator::roundMinorUnits(
                $this->validator->money($item->getRawOriginal('net_amount') ?? $item->net_amount, "{$itemContext} net payable"),
            );
            $this->require($storedItemNet === $itemNet, "{$itemContext}: net payable is inconsistent with the expense and taxes.");

            $sourceSupplier = $slip->transaction_type === PaymentSlip::TYPE_IMPORT
                ? (trim((string) $item->source_supplier_name) ?: trim((string) $slip->supplier->name))
                : trim((string) $slip->supplier->name);
            $descriptionContext = $buyerInvoice.'-'.$sourceSupplier;
            $itemVat = trim((string) ($item->vat_invoice_number ?? ''));
            if ($slip->transaction_type === PaymentSlip::TYPE_IMPORT) {
                $vat = $itemVat;
            } else {
                $vat = $itemVat !== '' ? $itemVat : $mainVat;
            }
            $this->require(mb_strlen($vat) <= 255, "{$itemContext}: VAT Invoice No. must be text of at most 255 characters.");

            $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'Expense', 'ledger', $this->resolver->resolve($item, $invoice), $item->item_name.' for '.$descriptionContext, $amount, null, $costCenter);
            if ($ppn > 0) {
                $ppnDescription = $vat === '' ? $descriptionContext : $vat.' -'.$descriptionContext;
                $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'PPN', 'ledger', config('erp-export.accounts.ppn'), $ppnDescription, $ppn, null);
            }
            if ($itemPph > 0) {
                $invoiceRows[] = new ErpJournalRow($invoice->invoice_number, $date, 'PPh', 'ledger', config('erp-export.accounts.pph_23'), 'PPh 23 '.$chargeType.' charge for '.$descriptionContext, null, $itemPph);
            }
            $expense += $amount;
            $ppnTotal += $ppn;
            $pphTotal += $itemPph;
        }

        $this->require($expense > 0, "{$context} has no positive expense value.");
        $pph = $pphTotal;
        $net = $expense + $ppnTotal - $pph;
        $storedSubtotal = InvoiceAmountCalculator::roundMinorUnits(
            $this->validator->money($invoice->getRawOriginal('subtotal_amount') ?? $invoice->subtotal_amount, "{$context} subtotal"),
        );
        $storedPpn = InvoiceAmountCalculator::roundMinorUnits(
            $this->validator->money($invoice->getRawOriginal('tax_addition_amount') ?? $invoice->tax_addition_amount, "{$context} PPN"),
        );
        $storedPph = InvoiceAmountCalculator::roundMinorUnits(
            $this->validator->money($invoice->getRawOriginal('tax_deduction_amount') ?? $invoice->tax_deduction_amount, "{$context} PPh"),
        );
        $storedNet = InvoiceAmountCalculator::roundMinorUnits(
            $this->validator->money($invoice->getRawOriginal('grand_total_amount') ?? $invoice->grand_total_amount, "{$context} net payable"),
        );
        $this->require($storedSubtotal === $expense && $storedPpn === $ppnTotal && $storedPph === $pph && $storedNet === $net, "{$context}: invoice totals are inconsistent with its items.");

        $mainContext = $buyerInvoice.'-'.trim((string) $slip->supplier->name);
        $supportingSupplierDetails = $invoice->items
            ->sortBy('line_number')
            ->filter(fn ($item): bool => filled($item->source_supplier_name))
            ->map(fn ($item): string => trim((string) $item->item_name).': '.trim((string) $item->source_supplier_name))
            ->unique()
            ->implode('; ');
        $supplierDescription = 'AP '.$chargeType.' charge for '.$mainContext;
        if ($supportingSupplierDetails !== '') {
            $supplierDescription .= ' | '.$supportingSupplierDetails;
        }
        $invoiceRows[] = new ErpJournalRow(
            $invoice->invoice_number,
            $date,
            'Supplier',
            'Supplier',
            $slip->supplier->code,
            $supplierDescription,
            null,
            $net,
            vatInvoiceNumber: $mainVat === '' ? null : $mainVat,
        );
        $this->validator->balance($invoiceRows, $context);

        return $invoiceRows;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['erp' => $message]);
        }
    }
}
