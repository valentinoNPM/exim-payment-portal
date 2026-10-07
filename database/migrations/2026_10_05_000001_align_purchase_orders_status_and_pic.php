<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menyelaraskan modul PO dengan perilaku pemakaian ECOUNT 2024-2026
 * (lihat C:/Users/valentino/Documents/work/hansoll-ecount/pola-data-po.md):
 *
 * 1. Status dipangkas dari tujuh nilai menjadi tiga — Baru, Dikirim ke Vendor,
 *    Selesai. Di ECOUNT hanya dua status yang benar-benar terpakai dari enam tab
 *    yang tersedia; keputusan val 5 Okt 2026: cukup tiga dan tanpa "Batal".
 * 2. PIC menjadi rujukan pengguna (`pic_user_id`), bukan teks bebas.
 *    `pic_name` tetap dipertahankan sebagai salinan nama untuk cetakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. normalisasi status lama ke tiga nilai baru ---
        DB::table('purchase_orders')->whereIn('status', ['draft', 'sent_to_admin'])->update(['status' => 'new']);
        DB::table('purchase_orders')->whereIn('status', ['signed', 'partially_received'])->update(['status' => 'sent_to_vendor']);
        // "cancelled" tidak punya padanan lagi karena status Batal dihapus;
        // baris lama dikembalikan ke "new" agar tetap bisa disunting manual.
        DB::table('purchase_orders')->where('status', 'cancelled')->update(['status' => 'new']);

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('status', 30)->default('new')->change();
        });

        // --- 2. PIC sebagai pengguna ---
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('pic_user_id')
                ->nullable()
                ->after('pic_name')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Isi PIC dari pembuat PO kalau belum ada, supaya kolom tidak kosong.
        DB::table('purchase_orders')
            ->whereNull('pic_user_id')
            ->update(['pic_user_id' => DB::raw('created_by')]);
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pic_user_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('status', 30)->default('draft')->change();
        });
    }
};
