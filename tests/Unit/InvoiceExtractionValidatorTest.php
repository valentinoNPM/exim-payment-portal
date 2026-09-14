<?php

namespace Tests\Unit;

use App\Services\InvoiceExtractionDataMapper;
use App\Services\InvoiceExtractionValidator;
use Tests\TestCase;

class InvoiceExtractionValidatorTest extends TestCase
{
    private function invoice(): array
    {
        return [
            'invoice_number' => 'INV-001', 'invoice_date' => '2026-09-11',
            'currency' => 'IDR', 'printed_subtotal' => 100.0,
            'printed_tax' => 11.0, 'printed_amount_due' => 111.0,
            'items' => [['item_name' => 'Service', 'line_total' => 100.0, 'source_quantity' => 11]],
            'evidence' => [],
        ];
    }

    public function test_missing_source_evidence_requires_review_even_when_arithmetic_matches(): void
    {
        $result = (new InvoiceExtractionValidator)->review($this->invoice(), null);
        $this->assertTrue($result['review_required']);
        $this->assertSame(100.0, $result['computed_line_total']);
    }

    public function test_mismatch_and_foreign_currency_are_flagged_without_changing_totals(): void
    {
        $invoice = $this->invoice();
        $invoice['currency'] = 'USD';
        $invoice['printed_subtotal'] = 200.0;
        $result = (new InvoiceExtractionValidator)->review($invoice, null);
        $this->assertStringContainsString('Jumlah item tidak cocok', implode(' ', $result['warnings']));
        $this->assertStringContainsString('Mata uang bukan IDR', implode(' ', $result['warnings']));
        $this->assertSame(200.0, $result['printed_subtotal']);
    }

    public function test_quote_must_exist_on_the_claimed_page(): void
    {
        $invoice = $this->invoice();
        $invoice['evidence']['invoice_number'] = ['page' => 2, 'quote' => 'Invoice INV-001'];
        $result = (new InvoiceExtractionValidator)->review($invoice, "[PAGE 1]\nInvoice INV-001\n[PAGE 2]\nOther text");
        $this->assertFalse($result['evidence']['invoice_number']['quote_verified']);
        $invoice['evidence']['invoice_number']['page'] = 1;
        $result = (new InvoiceExtractionValidator)->review($invoice, "[PAGE 1]\nInvoice INV-001");
        $this->assertTrue($result['evidence']['invoice_number']['quote_verified']);
    }

    public function test_printed_tax_and_amount_due_do_not_change_application_amounts(): void
    {
        $invoice = (new InvoiceExtractionValidator)->review($this->invoice(), null);
        $state = array_values((new InvoiceExtractionDataMapper)->toRepeaterState([$invoice], [1]))[0];
        $this->assertSame(100.0, $state['subtotal_amount']);
        $this->assertSame(100.0, $state['grand_total_amount']);
        $this->assertSame(1.0, array_values($state['items'])[0]['quantity']);
        $this->assertStringContainsString('111,00', $state['extraction_review']);
    }

    public function test_matching_quotes_and_values_reconcile_but_changed_amount_is_flagged(): void
    {
        $invoice = $this->invoice();
        $quotes = [
            'invoice_number' => 'Invoice INV-001',
            'invoice_date' => 'Date: 11/09/2026',
            'printed_subtotal' => 'Subtotal: 100,00',
            'printed_tax' => 'VAT: 11,00',
            'printed_amount_due' => 'Amount due: 111,00',
        ];
        foreach ($quotes as $field => $quote) {
            $invoice['evidence'][$field] = ['page' => 1, 'quote' => $quote];
        }
        $text = "[PAGE 1]\n".implode("\n", $quotes);
        $result = (new InvoiceExtractionValidator)->review($invoice, $text);
        $this->assertFalse($result['review_required']);
        $invoice['printed_subtotal'] = 1000.0;
        $result = (new InvoiceExtractionValidator)->review($invoice, $text);
        $this->assertTrue($result['review_required']);
        $this->assertFalse($result['evidence']['printed_subtotal']['quote_verified']);
    }
}
