<?php

namespace Tests\Unit;

use App\Models\Tax;
use App\Services\PaymentSlips\InvoiceDraftCalculator;
use App\Support\CurrencyFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDraftCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_itemized_and_invoice_legacy_keep_their_distinct_item_net_amounts(): void
    {
        $ppn = Tax::create(['code' => 'PPN11', 'name' => 'PPN 11', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH2', 'name' => 'PPh 2', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        $itemized = $this->calculate($ppn->id, $pph->id, true);
        $legacy = $this->calculate($ppn->id, $pph->id, false);

        $this->assertSame(CurrencyFormatter::formatFormState(218, 'IDR'), $itemized['net_amount']);
        $this->assertSame(CurrencyFormatter::formatFormState(200, 'IDR'), $legacy['net_amount']);
        $this->assertSame(CurrencyFormatter::formatFormStateNoDecimals(218, 'IDR'), $itemized['../../grand_total_amount']);
        $this->assertSame($itemized['../../grand_total_amount'], $legacy['../../grand_total_amount']);
    }

    /** @return array<string, mixed> */
    private function calculate(int $ppnId, int $pphId, bool $itemized): array
    {
        $state = [
            'quantity' => 2,
            'unit_price_amount' => 100,
            'ppn_tax_id' => $itemized ? $ppnId : null,
            'pph_tax_id' => $itemized ? $pphId : null,
            'tax_addition_amount' => 0,
            'tax_deduction_amount' => 0,
            '../../../../currency' => 'IDR',
            '../../ppn_tax_id' => $ppnId,
            '../../pph_tax_id' => $pphId,
            '../../items' => [[
                'quantity' => 2,
                'unit_price_amount' => 100,
                'ppn_tax_id' => $itemized ? $ppnId : null,
                'pph_tax_id' => $itemized ? $pphId : null,
            ]],
        ];
        $output = [];

        InvoiceDraftCalculator::recalculateFromItem(
            static fn (string $path): mixed => $state[$path] ?? null,
            static function (string $path, mixed $value) use (&$output): void {
                $output[$path] = $value;
            },
            $itemized,
        );

        return $output;
    }
}
