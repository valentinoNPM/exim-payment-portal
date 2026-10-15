<?php

namespace Tests\Unit;

use App\Services\InvoiceAmountCalculator;
use App\Support\MoneyRounding;
use PHPUnit\Framework\TestCase;

class MoneyRoundingTest extends TestCase
{
    public function test_currency_precision_and_half_up_boundaries(): void
    {
        foreach (['IDR' => 2.0, 'USD' => 1.51, 'EUR' => 1.51] as $currency => $expected) {
            $this->assertSame($expected, MoneyRounding::round(1.505, $currency));
            $this->assertSame(-$expected, MoneyRounding::round(-1.505, $currency));
        }
        $this->assertSame(InvoiceAmountCalculator::calculateForCurrency(1500.25, 11, 2, 'USD'), InvoiceAmountCalculator::calculateForCurrency(1500.25, 11, 2, 'EUR'));
    }
}
