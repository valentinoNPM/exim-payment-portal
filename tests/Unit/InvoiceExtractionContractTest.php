<?php

namespace Tests\Unit;

use App\Services\GeminiInvoiceExtractor;
use App\Services\InvoiceExtractionDataMapper;
use Tests\TestCase;

class InvoiceExtractionContractTest extends TestCase
{
    public function test_new_contract_preserves_printed_facts_and_maps_only_line_total(): void
    {
        $extractor = new class extends GeminiInvoiceExtractor
        {
            public function normalize(array $data): array
            {
                return $this->validateExtractedInvoices($data);
            }
        };
        $result = $extractor->normalize([[
            'invoice_number' => 'SUPPLIER-123', 'invoice_date' => '2026-09-11',
            'currency' => 'IDR', 'printed_subtotal' => 185000,
            'printed_tax' => 20350, 'printed_amount_due' => 205350,
            'evidence' => ['invoice_number' => ['page' => 1, 'quote' => 'Invoice SUPPLIER-123']],
            'items' => [['item_name' => 'Fee', 'source_quantity' => 11, 'unit_price' => null, 'line_total' => 185000]],
        ]]);
        $this->assertSame(205350.0, $result[0]['printed_amount_due']);
        $this->assertSame(11.0, $result[0]['items'][0]['source_quantity']);
        $this->assertSame(1, $result[0]['evidence']['invoice_number']['page']);
        $state = array_values((new InvoiceExtractionDataMapper)->toRepeaterState($result, [1]))[0];
        $this->assertSame(185000.0, $state['subtotal_amount']);
    }
}
