<?php

namespace App\Services;

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

    public static function roundRupiah(float $amount): float
    {
        return round($amount, 0, PHP_ROUND_HALF_UP);
    }

    public static function roundMinorUnits(int $amount): int
    {
        return (int) (self::roundRupiah($amount / 100) * 100);
    }
}
