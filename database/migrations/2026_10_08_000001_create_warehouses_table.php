<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master gudang/lokasi ECOUNT + relasi gudang pada PO.
 *
 * Sumber: ekspor "Daftar Lokasi" ECOUNT (gudang.xlsx) -> database/data/warehouses.csv (33 lokasi).
 * `source_code` = "Kode Lokasi" ECOUNT ('00001'..'00014', 'GA', 'WH', 'SP', ...) dan dipakai
 * sebagai identitas master, sama seperti kode barang dan kode supplier.
 *
 * Catatan penting: kolom `delivery_location` di purchase_orders TIDAK disentuh. Itu blok alamat
 * "Ship To" pada cetakan PO — konsep berbeda dari gudang, dan tidak boleh tercampur.
 *
 * `warehouse_id` sengaja boleh kosong: nilai gudang per dokumen belum pernah ikut terimpor
 * (kolom kode_gudang/nama_gudang di berkas impor 0 terisi dari 26.397 baris). Pengisiannya
 * menunggu ekspor slip PO yang difilter per gudang di ECOUNT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('source_code', 50)->unique();
            $table->string('name');
            $table->string('type', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('branch')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('division_id')
                ->constrained('warehouses')
                ->nullOnDelete();
        });

        $this->seedFromCsv();
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::dropIfExists('warehouses');
    }

    private function seedFromCsv(): void
    {
        $path = database_path('data/warehouses.csv');

        if (! is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($lines); // baris kolom

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

        if ($rows !== []) {
            DB::table('warehouses')->upsert(
                $rows,
                ['source_code'],
                ['name', 'type', 'is_active', 'branch', 'updated_at'],
            );
        }
    }
};
