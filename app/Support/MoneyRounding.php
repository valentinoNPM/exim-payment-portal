<?php

namespace App\Support;

final class MoneyRounding
{
    public static function round(float $amount, string $currency): float
    {
        return round($amount, CurrencyFormatter::usesDecimals($currency) ? 2 : 0, PHP_ROUND_HALF_UP);
    }
}
