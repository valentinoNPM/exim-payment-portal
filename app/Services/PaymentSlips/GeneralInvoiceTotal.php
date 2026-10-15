<?php

namespace App\Services\PaymentSlips;

use App\Support\MoneyRounding;
use Illuminate\Validation\ValidationException;

final class GeneralInvoiceTotal
{
    public static function calculate(float $subtotal, float $addition, float $deduction, float $discount, float $shipping, string $currency): float
    {
        if ($discount < 0 || $shipping < 0 || $discount >= 1e16 || $shipping >= 1e16) {
            throw ValidationException::withMessages(['invoices' => 'Diskon dan ongkir harus nonnegatif dan dalam batas nominal.']);
        }

        $total = MoneyRounding::round($subtotal + $addition - $deduction - MoneyRounding::round($discount, $currency) + MoneyRounding::round($shipping, $currency), $currency);
        if ($total < 0) {
            throw ValidationException::withMessages(['invoices' => 'Total invoice tidak boleh negatif.']);
        }

        return $total;
    }
}
