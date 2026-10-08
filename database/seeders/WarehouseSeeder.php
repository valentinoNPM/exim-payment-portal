<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Mengisi/perbarui master gudang dari database/data/warehouses.csv (ekspor "Daftar Lokasi" ECOUNT).
 *
 * Aman dijalankan berulang: dicocokkan lewat `source_code`, jadi lokasi baru dari ekspor
 * berikutnya bertambah tanpa menggandakan yang sudah ada.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/warehouses.csv');

        if (! is_file($path)) {
            $this->command?->warn("Berkas tidak ditemukan: {$path}");

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($lines);

        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            $kolom = str_getcsv($line);

            if (count($kolom) < 2 || trim($kolom[0]) === '') {
                continue;
            }

            $rows[] = [
                'source_code' => trim($kolom[0]),
                'name' => trim($kolom[1]),
                'type' => trim($kolom[2] ?? '') ?: null,
                'is_active' => strtolower(trim($kolom[3] ?? 'ya')) !== 'tidak',
                'branch' => trim($kolom[4] ?? '') ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            $this->command?->warn('Tidak ada baris lokasi yang bisa dibaca.');

            return;
        }

        DB::table('warehouses')->upsert($rows, ['source_code'], ['name', 'type', 'is_active', 'branch', 'updated_at']);

        $this->command?->info('Master gudang: '.count($rows).' lokasi diproses.');
    }
}
