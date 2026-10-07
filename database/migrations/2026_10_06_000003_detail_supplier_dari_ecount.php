<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom detail supplier yang selama ini belum ada di tabel suppliers.
 *
 * Sumber data: hasil tarikan layar "Daftarkan Pelanggan/Vendor" ECOUNT
 * (lihat C:\Users\valentino\Documents\work\hansoll-ecount\supplier-detail).
 *
 * Kolom baru:
 *  - source_code  : kode pelanggan/vendor ECOUNT (mis. 00254). Identitas asli dari ECOUNT,
 *                   karena kode di exim berasal dari modul pembayaran dan tidak sama.
 *  - email        : alamat surel supplier
 *  - phone        : nomor telepon/mobile apa adanya (mis. 0877-1823-9768)
 *  - phone_digit  : nomor yang sama tanpa tanda baca (untuk WhatsApp/tautan)
 *  - fax          : nomor faks
 *  - address_2    : alamat pengiriman (Alamat 2 di ECOUNT)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('source_code')->nullable()->after('code');
            $table->string('email')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('phone_digit', 30)->nullable()->after('phone');
            $table->string('fax')->nullable()->after('phone_digit');
            $table->text('address_2')->nullable()->after('address');

            $table->index('source_code');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['source_code']);
            $table->dropColumn(['source_code', 'email', 'phone', 'phone_digit', 'fax', 'address_2']);
        });
    }
};
