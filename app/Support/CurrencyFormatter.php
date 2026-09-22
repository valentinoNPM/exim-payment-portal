<?php

namespace App\Support;

final class CurrencyFormatter
{
    /**
     * Format a numeric amount with the appropriate currency symbol and locale rules.
     *
     * IDR: Rp 1.500.000 (dot thousands, no decimals)
     * USD: USD 1,500.00 (comma thousands, dot decimal, always 2 decimals)
     */
    public static function format(float|string|null $amount, string $currency = 'IDR'): string
    {
        $value = (float) ($amount ?? 0);

        return match ($currency) {
            'USD' => 'USD '.number_format($value, 2, '.', ','),
            default => 'Rp '.number_format($value, 0, ',', '.'),
        };
    }

    /**
     * Return the currency prefix string for use in form field prefixes.
     */
    public static function prefix(string $currency = 'IDR'): string
    {
        return $currency === 'USD' ? 'USD' : 'Rp';
    }

    /**
     * Format state for display in Filament form fields.
     *
     * IDR: 1.500.000,00 (dot thousands, comma decimal)
     * USD: 1,500,000.00 (comma thousands, dot decimal)
     */
    public static function formatFormState(float|string|null $state, string $currency = 'IDR', int $decimals = 2): string
    {
        $value = (float) ($state ?? 0);

        return match ($currency) {
            'USD' => number_format($value, $decimals, '.', ','),
            default => number_format($value, $decimals, ',', '.'),
        };
    }

    /**
     * Format state for form display without decimals (used for tax/grand total amounts).
     */
    public static function formatFormStateNoDecimals(float|string|null $state, string $currency = 'IDR'): string
    {
        $value = (float) ($state ?? 0);

        return match ($currency) {
            'USD' => number_format($value, 2, '.', ','),
            default => number_format($value, 0, ',', '.'),
        };
    }
}
