<?php

namespace App\Services\Erp;

use Illuminate\Validation\ValidationException;

class ErpJournalValidator
{
    public function money(mixed $value, string $context): int
    {
        // Integer minor units avoid floating point balance comparisons and tax rounding.
        if (! is_scalar($value) || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', (string) $value)) {
            throw ValidationException::withMessages(['erp' => "Invalid money value for {$context}."]);
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $value), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public function balance(array $rows, string $context): void
    {
        $debit = array_sum(array_map(fn ($row) => $row->debit ?? 0, $rows));
        $credit = array_sum(array_map(fn ($row) => $row->credit ?? 0, $rows));
        if ($debit !== $credit) {
            throw ValidationException::withMessages(['erp' => "{$context}: total debit Rp ".number_format($debit / 100, 2).', total credit Rp '.number_format($credit / 100, 2).', difference Rp '.number_format(($debit - $credit) / 100, 2).'. The journal cannot be exported.']);
        }
    }
}
