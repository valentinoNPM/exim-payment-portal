<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai PO yang berasal dari ECOUNT (impor data lama), supaya:
 *  - PO hasil impor bisa dibedakan dari PO yang dibuat di exim;
 *  - kunci dokumen ECOUNT (Tanggal-No., mis. "05/10/2026 -7") tersimpan sebagai
 *    jejak asal-usul, karena Laporan PO tidak memuat nomor PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('source', 20)->default('exim')->after('status')->index();
            $table->string('source_code', 40)->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_code']);
        });
    }
};
