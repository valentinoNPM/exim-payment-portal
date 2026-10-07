<?php

namespace App\Services\PurchaseOrders;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PurchaseOrderNumberGenerator
{
    /** @return array{company_code: string, sequence_number: int, po_number: string} */
    public function reserve(CarbonInterface $date): array
    {
        $companyCode = strtoupper((string) config('purchase-orders.company_code', 'HIJ'));
        $scopeKey = $this->scopeKey($date);

        return DB::transaction(function () use ($companyCode, $scopeKey, $date): array {
            DB::table('purchase_order_sequences')->insertOrIgnore([
                'company_code' => $companyCode,
                'scope_key' => $scopeKey,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('purchase_order_sequences')
                ->where('company_code', $companyCode)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->value('last_number');

            // Data ECOUNT diimpor langsung dengan sequence_number aslinya dan tidak selalu
            // melewati tabel sequence. Selalu sejajarkan penghitung dengan urutan terbesar
            // yang sudah ada agar PO baru/backdate tidak menabrak dokumen hasil impor.
            $existingMaximum = (int) DB::table('purchase_orders')
                ->where('company_code', $companyCode)
                ->whereDate('po_date', $date->toDateString())
                ->max('sequence_number');

            $next = max((int) $sequence, $existingMaximum) + 1;

            DB::table('purchase_order_sequences')
                ->where('company_code', $companyCode)
                ->where('scope_key', $scopeKey)
                ->update([
                    'last_number' => $next,
                    'updated_at' => now(),
                ]);

            return [
                'company_code' => $companyCode,
                'sequence_number' => $next,
                'po_number' => sprintf('PO/%s/%s-%06d', $companyCode, $date->format('dmY'), $next),
            ];
        });
    }

    /**
     * Nomor berikutnya TANPA memesan urutan — untuk ditampilkan sebagai draf di halaman
     * "Buat PO". Belum paten: nomor sebenarnya dipesan lewat reserve() saat PO disimpan.
     *
     * @return array{company_code: string, sequence_number: int, po_number: string}
     */
    public function preview(CarbonInterface $date): array
    {
        $companyCode = strtoupper((string) config('purchase-orders.company_code', 'HIJ'));

        $sequence = (int) DB::table('purchase_order_sequences')
            ->where('company_code', $companyCode)
            ->where('scope_key', $this->scopeKey($date))
            ->value('last_number');

        $existingMaximum = (int) DB::table('purchase_orders')
            ->where('company_code', $companyCode)
            ->whereDate('po_date', $date->toDateString())
            ->max('sequence_number');

        $next = max($sequence, $existingMaximum) + 1;

        return [
            'company_code' => $companyCode,
            'sequence_number' => $next,
            'po_number' => sprintf('PO/%s/%s-%06d', $companyCode, $date->format('dmY'), $next),
        ];
    }

    private function scopeKey(CarbonInterface $date): string
    {
        return match (config('purchase-orders.sequence_scope', 'daily')) {
            'global' => 'global',
            'yearly' => $date->format('Y'),
            'monthly' => $date->format('Ym'),
            default => $date->format('Ymd'),
        };
    }
}
