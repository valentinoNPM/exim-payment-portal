<?php

namespace Tests\Unit;

use App\Services\InvoiceAmountCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceAmountCalculatorTest extends TestCase
{
    public function test_usd_calculation_preserves_two_decimal_minor_units(): void
    {
        $amounts = InvoiceAmountCalculator::calculateForCurrency(1500.25, 11, 2, 'USD');

        $this->assertSame(1500.25, $amounts['subtotal']);
        $this->assertSame(165.03, $amounts['tax_addition']);
        $this->assertSame(30.01, $amounts['tax_deduction']);
        $this->assertSame(1635.27, $amounts['grand_total']);
    }

    public function test_it_rounds_each_component_before_calculating_amount_paid(): void
    {
        $amounts = InvoiceAmountCalculator::calculate(11067476, 11, 2);

        $this->assertSame(1217422.0, $amounts['tax_addition']);
        $this->assertSame(221350.0, $amounts['tax_deduction']);
        $this->assertSame(12063548.0, $amounts['grand_total']);
    }

    public function test_sum_of_rounded_invoice_totals_matches_payment_slip_total(): void
    {
        $first = InvoiceAmountCalculator::calculate(5174228.79, 11, 2);
        $second = InvoiceAmountCalculator::calculate(5174090.02, 11, 2);

        $this->assertSame(5639909.0, $first['grand_total']);
        $this->assertSame(5639758.0, $second['grand_total']);
        $this->assertSame(11279667.0, $first['grand_total'] + $second['grand_total']);
    }
}
