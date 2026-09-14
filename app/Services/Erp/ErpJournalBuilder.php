<?php

namespace App\Services\Erp;

use App\Data\ErpJournalRow;
use App\Models\PaymentSlip;
use App\Services\InvoiceAmountCalculator;
use Illuminate\Validation\ValidationException;

class ErpJournalBuilder
{
    public function __construct(private AccountResolver $resolver, private ErpJournalValidator $validator) {}

    /** @return list<ErpJournalRow> */
    public function build(PaymentSlip $slip, array $vatNumbers = []): array
    {
        $slip->loadMissing(['supplier', 'buyer', 'invoices.items.chartOfAccount', 'erpExportItem']);
        $this->require($slip->erpExportItem === null, 'This payment slip has already been exported.');
        $this->require($slip->status === 'approved', 'Only approved payment slips can be exported.');
        $this->require(filled($slip->supplier?->code), 'Supplier or supplier code is missing.');
        $costCenter = config('erp-export.cost_centers.'.$slip->transaction_type);
        $this->require(filled($costCenter), 'Import/Export type is invalid.');
        $this->require($slip->invoices->isNotEmpty(), 'Payment slip has no invoices.');
        $rows = [];
        foreach ($slip->invoices->sortBy('id') as $invoice) {
            $context = "Invoice {$invoice->invoice_number}";
            $this->require($invoice->items->isNotEmpty(), "{$context} has no expense items.");
            $this->require($invoice->invoice_date !== null, "{$context} has no invoice date.");
            $date = $invoice->invoice_date->format('Y-m-d');
            $descriptionContext = implode(' ', array_filter([
                trim((string) $slip->buyer?->name),
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

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['erp' => $message]);
        }
    }
}
