<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyPurchaseOrderCurrencyCorrections extends Command
{
    protected $signature = 'po:apply-currency-corrections
        {--berkas= : CSV kunci ECOUNT dan hasil verifikasi mata uang}
        {--apply : Tulis koreksi ke basis data (tanpa ini hanya laporan)}';

    protected $description = 'Terapkan koreksi mata uang PO hasil verifikasi ECOUNT secara idempoten';

    public function handle(): int
    {
        $file = (string) $this->option('berkas');
        if ($file === '' || ! is_file($file)) {
            $this->error("Berkas koreksi tidak ditemukan: {$file}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($file);
        $ready = [];
        $unchanged = 0;
        $missing = [];
        $invalid = [];

        foreach ($rows as $row) {
            $sourceCode = trim((string) ($row['kunci_ecount'] ?? ''));
            $currency = strtoupper(trim((string) ($row['currency_baru'] ?? '')));
            $grandTotal = $row['grand_baru'] ?? null;

            if ($sourceCode === '' || ! in_array($currency, PurchaseOrder::CURRENCIES, true) || ! is_numeric($grandTotal)) {
                $invalid[] = $sourceCode !== '' ? $sourceCode : '(kunci kosong)';

                continue;
            }

            $purchaseOrder = PurchaseOrder::query()->where('source_code', $sourceCode)->first();
            if (! $purchaseOrder) {
                $missing[] = $sourceCode;

                continue;
            }

            if ($purchaseOrder->currency === $currency
                && abs((float) $purchaseOrder->grand_total_amount - (float) $grandTotal) < 0.005) {
                $unchanged++;

                continue;
            }

            $ready[] = [$purchaseOrder, $currency, (float) $grandTotal];
        }

        $this->table(['Pemeriksaan', 'Jumlah'], [
            ['Baris koreksi terbaca', count($rows)],
            ['Siap diperbarui', count($ready)],
            ['Sudah sesuai', $unchanged],
            ['PO tidak ditemukan', count($missing)],
            ['Baris tidak valid', count($invalid)],
        ]);

        if ($missing !== [] || $invalid !== []) {
            foreach (array_slice($missing, 0, 10) as $sourceCode) {
                $this->warn("PO tidak ditemukan: {$sourceCode}");
            }
            foreach (array_slice($invalid, 0, 10) as $sourceCode) {
                $this->warn("Koreksi tidak valid: {$sourceCode}");
            }

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN — tidak ada data yang ditulis.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($ready): void {
            foreach ($ready as [$purchaseOrder, $currency, $grandTotal]) {
                $purchaseOrder->forceFill([
                    'currency' => $currency,
                    'grand_total_amount' => $grandTotal,
                ])->save();
            }
        });

        $this->info('Selesai — '.count($ready).' PO diperbarui.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, string|null>> */
    private function readCsv(string $file): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($value): string => trim((string) $value, "\u{FEFF} \t\n\r"), $header);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, array_pad($values, count($header), null));
        }

        fclose($handle);

        return $rows;
    }
}
