<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen hasil impor ECOUNT yang berstatus "Dalam Proses" semula dicatat sebagai
 * sent_to_vendor ("Dikirim ke Vendor") — istilah yang berbeda arti dan menyesatkan
 * pembaca laporan. Statusnya dipindahkan ke in_progress ("Dalam Proses").
 *
 * Hanya menyentuh dokumen impor (source_code terisi), supaya PO yang dibuat di aplikasi
 * dan memang dikirim ke vendor tidak ikut berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'sent_to_vendor')
            ->whereNotNull('source_code')
            ->where('source_code', '<>', '')
            ->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'in_progress')
            ->whereNotNull('source_code')
            ->where('source_code', '<>', '')
            ->update(['status' => 'sent_to_vendor']);
    }
};
