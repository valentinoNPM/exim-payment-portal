<?php

namespace App\Services;

use App\Support\CurrencyFormatter;
use App\Support\MoneyRounding;

final class InvoiceAmountCalculator
{
    /**
     * @return array{subtotal: float, tax_addition: float, tax_deduction: float, grand_total: float}
     */
    public static function calculate(float $subtotal, ?float $additionRate, ?float $deductionRate): array
    {
        $roundedSubtotal = self::roundRupiah($subtotal);
        $addition = $additionRate === null ? 0.0 : self::roundRupiah($subtotal * ($additionRate / 100));
        $deduction = $deductionRate === null ? 0.0 : self::roundRupiah($subtotal * ($deductionRate / 100));

        return [
            'subtotal' => $roundedSubtotal,
            'tax_addition' => $addition,
            'tax_deduction' => $deduction,
            'grand_total' => $roundedSubtotal + $addition - $deduction,
        ];
    }

    /**
     * @return array{subtotal: float, tax_addition: float, tax_deduction: float, grand_total: float}
     */
    public static function calculateForCurrency(float $subtotal, ?float $additionRate, ?float $deductionRate, string $currency): array
    {
        if (! CurrencyFormatter::usesDecimals($currency)) {
            return self::calculate($subtotal, $additionRate, $deductionRate);
        }

        $roundedSubtotal = MoneyRounding::round($subtotal, $currency);
        $addition = $additionRate === null ? 0.0 : MoneyRounding::round($subtotal * ($additionRate / 100), $currency);
        $deduction = $deductionRate === null ? 0.0 : MoneyRounding::round($subtotal * ($deductionRate / 100), $currency);

        return [
            'subtotal' => $roundedSubtotal,
            'tax_addition' => $addition,
            'tax_deduction' => $deduction,
            'grand_total' => $roundedSubtotal + $addition - $deduction,
        ];
    }

    public static function roundRupiah(float $amount): float
    {
        return MoneyRounding::round($amount, 'IDR');
    }

    public static function roundMinorUnits(int $amount): int
    {
        return (int) (self::roundRupiah($amount / 100) * 100);
    }
}
