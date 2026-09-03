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
        $this->assertSame(2270000.0, $invoices[0]['subtotal_amount']);
        $this->assertSame($invoices[0]['subtotal_amount'], $invoices[0]['grand_total_amount']);
        $this->assertIsFloat($invoices[0]['subtotal_amount']);
        $this->assertCount(2, $invoices[0]['items']);
    }

    public function test_it_rejects_empty_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InvoiceExtractionDataMapper)->toRepeaterState([], []);
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
}
