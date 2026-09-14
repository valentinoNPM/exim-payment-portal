<?php

namespace App\Services;

use InvalidArgumentException;

class InvoiceExtractionDataMapper
{
    public function toRepeaterState(array $invoices, array $documentIds): array
    {
        if ($invoices === [] || $documentIds === []) {
            throw new InvalidArgumentException('Invoices and document IDs are required.');
        }

        $state = [];

        foreach ($invoices as $index => $invoice) {
            $documentId = count($documentIds) === 1
                ? $documentIds[0]
                : ($documentIds[$index] ?? end($documentIds));
            $items = [];
            $subtotal = 0.0;

            foreach ($invoice['items'] as $item) {
                // The extractor returns a billed line total, not a per-unit price.
                // Normalize to one accounting line to avoid multiplying twice or
                // introducing rounding errors by dividing totals into unit prices.
                $quantity = 1.0;
                $unitPrice = (float) ($item['line_total'] ?? $item['original_price'] ?? 0);
                $subtotal += $quantity * $unitPrice;
                $items[(string) str()->uuid()] = [
                    'item_name' => $item['item_name'],
                    'quantity' => $quantity,
                    'unit_price_amount' => $unitPrice,
                ];
            }

            $state[(string) str()->uuid()] = [
                'invoice_number' => $invoice['invoice_number'],
                'invoice_date' => $invoice['invoice_date'],
                'document_file_id' => $documentId,
                'ppn_tax_id' => null,
                'pph_tax_id' => null,
                'items' => $items,
                'subtotal_amount' => $subtotal,
                'tax_addition_amount' => 0,
                'tax_deduction_amount' => 0,
                'grand_total_amount' => $subtotal,
                'extraction_review' => $this->reviewSummary($invoice),
            ];
        }

        return $state;
    }

    private function reviewSummary(array $invoice): string
    {
        if (! isset($invoice['schema_version'])) {
            return '';
        }
        $lines = ['Mata uang dokumen: '.($invoice['currency'] ?? 'Belum diketahui')];
        foreach (['printed_subtotal' => 'Subtotal PDF', 'printed_tax' => 'Pajak PDF', 'printed_amount_due' => 'Total tagihan PDF'] as $field => $label) {
            $lines[] = $label.': '.(isset($invoice[$field]) ? number_format($invoice[$field], 2, ',', '.') : 'Belum diketahui');
        }
        $lines[] = 'Nominal PDF adalah referensi; Amount Dibayar tetap mengikuti pengaturan pajak aplikasi.';
        foreach ($invoice['evidence'] ?? [] as $field => $evidence) {
            if (is_string($evidence['quote'] ?? null)) {
                $lines[] = $field.' — halaman '.($evidence['page'] ?? '?').': '.$evidence['quote'];
            }
        }
        foreach ($invoice['warnings'] ?? [] as $warning) {
            $lines[] = 'Periksa: '.$warning;
        }

        return implode("\n", $lines);
    }
}
