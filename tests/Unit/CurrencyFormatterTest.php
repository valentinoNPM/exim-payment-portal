<?php

namespace Tests\Unit;

use App\Support\CurrencyFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CurrencyFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_idr_with_rupiah_prefix_and_no_decimals(): void
    {
        $this->assertSame('Rp 1.500.000', CurrencyFormatter::format(1500000, 'IDR'));
    }

    #[Test]
    public function it_formats_usd_with_usd_prefix_and_two_decimals(): void
    {
        $this->assertSame('USD 1,500,000.00', CurrencyFormatter::format(1500000, 'USD'));
    }

    #[Test]
    public function it_formats_zero_idr(): void
    {
        $this->assertSame('Rp 0', CurrencyFormatter::format(0, 'IDR'));
    }

    #[Test]
    public function it_formats_zero_usd(): void
    {
        $this->assertSame('USD 0.00', CurrencyFormatter::format(0, 'USD'));
    }

    #[Test]
    public function it_formats_small_decimal_usd(): void
    {
        $this->assertSame('USD 1,234.56', CurrencyFormatter::format(1234.56, 'USD'));
    }

    #[Test]
    public function it_formats_idr_without_decimals(): void
    {
        $this->assertSame('Rp 1.235', CurrencyFormatter::format(1234.56, 'IDR'));
    }

    #[Test]
    public function it_defaults_to_idr_when_no_currency_given(): void
    {
        $this->assertSame('Rp 100.000', CurrencyFormatter::format(100000));
    }

    #[Test]
    public function it_accepts_string_amounts(): void
    {
        $this->assertSame('USD 500.50', CurrencyFormatter::format('500.50', 'USD'));
        $this->assertSame('Rp 501', CurrencyFormatter::format('500.50', 'IDR'));
    }

    #[Test]
    public function prefix_returns_correct_values(): void
    {
        $this->assertSame('Rp', CurrencyFormatter::prefix('IDR'));
        $this->assertSame('USD', CurrencyFormatter::prefix('USD'));
        $this->assertSame('Rp', CurrencyFormatter::prefix());
    }

    #[Test]
    public function format_form_state_formats_correctly(): void
    {
        $this->assertSame('1.500.000,00', CurrencyFormatter::formatFormState(1500000, 'IDR'));
        $this->assertSame('1,500,000.00', CurrencyFormatter::formatFormState(1500000, 'USD'));
    }

    #[Test]
    public function format_form_state_no_decimals_formats_correctly(): void
    {
        $this->assertSame('1.500.000', CurrencyFormatter::formatFormStateNoDecimals(1500000, 'IDR'));
        $this->assertSame('1,500,000.00', CurrencyFormatter::formatFormStateNoDecimals(1500000, 'USD'));
    }
}
