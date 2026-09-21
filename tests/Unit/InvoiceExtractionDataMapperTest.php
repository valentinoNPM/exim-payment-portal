<?php

namespace Tests\Unit;

use App\Services\InvoiceExtractionDataMapper;
use InvalidArgumentException;
use Tests\TestCase;

class InvoiceExtractionDataMapperTest extends TestCase
{
    public function test_it_maps_multiple_invoices_and_keeps_amounts_numeric(): void
    {
        $state = (new InvoiceExtractionDataMapper)->toRepeaterState([
            [
                'invoice_number' => 'INV-001',
                'invoice_date' => '2026-09-03',
                'items' => [
                    ['item_name' => 'Custom Clearance', 'qty' => 1, 'original_price' => 400000],
                    ['item_name' => 'Agency Fee', 'qty' => 2, 'original_price' => 935000],
                ],
            ],
            [
                'invoice_number' => 'INV-002',
                'invoice_date' => '2026-09-03',
                'items' => [
                    ['item_name' => 'Storage', 'qty' => 1, 'original_price' => 10015438],
                ],
            ],
        ], [99]);

        $invoices = array_values($state);

        $this->assertCount(2, $invoices);
        $this->assertSame(99, $invoices[0]['document_file_id']);
        $this->assertSame(1335000.0, $invoices[0]['subtotal_amount']);
        $this->assertSame($invoices[0]['subtotal_amount'], $invoices[0]['grand_total_amount']);
        $this->assertIsFloat($invoices[0]['subtotal_amount']);
        $this->assertCount(2, $invoices[0]['items']);
    }

    public function test_it_rejects_empty_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InvoiceExtractionDataMapper)->toRepeaterState([], []);
    }

    public function test_billed_line_totals_are_not_multiplied_by_document_quantity(): void
    {
        $state = (new InvoiceExtractionDataMapper)->toRepeaterState([[
            'invoice_number' => 'E832792138',
            'invoice_date' => '2026-09-01',
            'items' => array_map(static fn (int $amount): array => [
                'item_name' => 'Service charge',
                'qty' => 11,
                'original_price' => $amount,
            ], [185000, 400000, 772800, 2898000, 277500]),
        ]], [99]);

        $invoice = array_values($state)[0];
        $this->assertSame('E832792138', $invoice['invoice_number']);
        $this->assertSame(4533300.0, $invoice['subtotal_amount']);
        $this->assertSame(4533300.0, $invoice['grand_total_amount']);
        $persistedTotal = 0.0;
        foreach ($invoice['items'] as $item) {
            $this->assertSame(1.0, $item['quantity']);
            $persistedTotal += $item['quantity'] * $item['unit_price_amount'];
        }
        $this->assertSame(4533300.0, $persistedTotal);
    }

    public function test_fns_item_totals_roll_up_to_the_printed_invoice_total(): void
    {
        $state = (new InvoiceExtractionDataMapper)->toRepeaterState([[
            'invoice_number' => 'IMP/FNS/2DU/004/VII/2026',
            'invoice_date' => '2026-08-05',
            'items' => [
                ['item_name' => 'Custom Clearance', 'qty' => 1, 'original_price' => 436000],
                ['item_name' => 'Agency Fee', 'qty' => 1, 'original_price' => 935000],
                ['item_name' => 'Storage', 'qty' => 1, 'original_price' => 10015438],
                ['item_name' => 'Add Storage 5%', 'qty' => 1, 'original_price' => 500772],
                ['item_name' => 'Add Custom', 'qty' => 1, 'original_price' => 907000],
                ['item_name' => 'Biaya Forklift', 'qty' => 1, 'original_price' => 200000],
                ['item_name' => 'Adm Document', 'qty' => 1, 'original_price' => 150000],
                ['item_name' => 'Adm Fee', 'qty' => 1, 'original_price' => 374000],
                ['item_name' => 'Trucking CDD', 'qty' => 1, 'original_price' => 4000000],
                ['item_name' => 'Biaya Tol', 'qty' => 1, 'original_price' => 688000],
            ],
        ]], [99]);

        $invoice = array_values($state)[0];

        $this->assertSame(18206210.0, $invoice['subtotal_amount']);
        $this->assertSame(18206210.0, $invoice['grand_total_amount']);
    }

    public function test_import_mapping_prefers_pre_tax_rate_for_every_payable_invoice(): void
    {
        $invoices = [
            [
                'invoice_number' => 'IMP/FNS/2B/015/VII/2026',
                'invoice_date' => '2026-08-31',
                'items' => [['item_name' => 'Custom Clearance', 'unit_price' => 400000, 'line_total' => 436000]],
            ],
            [
                'invoice_number' => 'IMP/FNS/2B/016/VII/2026',
                'invoice_date' => '2026-08-31',
                'items' => [['item_name' => 'Agency Fee', 'unit_price' => 935000, 'line_total' => 935000]],
            ],
        ];

        $state = array_values((new InvoiceExtractionDataMapper)->toRepeaterState(
            $invoices,
            [99],
            preferBaseAmount: true,
        ));

        $this->assertCount(2, $state);
        $this->assertSame('IMP/FNS/2B/015/VII/2026', $state[0]['invoice_number']);
        $this->assertSame(400000.0, $state[0]['subtotal_amount']);
        $this->assertSame(400000.0, array_values($state[0]['items'])[0]['unit_price_amount']);
        $this->assertSame('IMP/FNS/2B/016/VII/2026', $state[1]['invoice_number']);
    }
}
