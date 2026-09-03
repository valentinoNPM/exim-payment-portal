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
                $quantity = (float) ($item['qty'] ?? 1);
                $unitPrice = (float) ($item['original_price'] ?? 0);
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
            ];
        }

        return $state;
    }
}
