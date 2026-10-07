<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas PO hasil impor ECOUNT adalah KUNCI ECOUNT (Tanggal-No., mis. "01/09/2026 -4"),
 * bukan nomor PO.
 *
 * Dasarnya temuan penyusunan ulang 2024-2026 (5.211 dokumen):
 *  - kunci ECOUNT unik 5.211/5.211 dan cocok dengan kolom `kunci_ecount` di berkas hasil olah;
 *  - nomor PO TIDAK unik: 48 nomor dipakai dua dokumen berbeda (96 dokumen), sebab penomoran
 *    ECOUNT tidak ditegakkan unik dan dokumen yang dihapus meninggalkan lubang nomor.
 *
 * Karena itu:
 *  - indeks unik pada `po_number` DILEPAS (tetap diberi indeks biasa supaya pencarian dan
 *    rujukan ke dokumen kertas tetap cepat) — nomor PO jadi label, bukan identitas;
 *  - `source_code` (kunci ECOUNT, diisi importir) diberi indeks unik sebagai identitas sebenarnya.
 *
 * Kolom di ECOUNT juga berlapis (tanggal + urutan harian); di sini lapisan itu tersedia sebagai
 * `po_date` + `sequence_number` (unik bersama `company_code`) dan kunci mentahnya di `source_code`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_orders_po_number_unique');
            $table->index('po_number');
            // indeks biasa pada source_code dari migrasi sebelumnya digantikan indeks unik
            $table->dropIndex(['source_code']);
            $table->unique('source_code', 'purchase_orders_source_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_orders_source_code_unique');
            $table->index('source_code');
            $table->dropIndex(['po_number']);
            $table->unique('po_number');
        });
    }
};
