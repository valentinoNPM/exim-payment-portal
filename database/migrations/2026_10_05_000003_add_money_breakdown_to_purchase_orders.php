<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melengkapi elemen uang header PO supaya total dokumen dihitung penuh:
 * subtotal + penambahan pajak (PPN) - potongan pajak (PPh) - diskon + biaya kirim.
 *
 * Nama kolom pajak sengaja disamakan dengan modul invoice
 * (`tax_addition_amount` / `tax_deduction_amount`) agar konsisten.
 * `tax_amount` lama tetap dipertahankan sebagai pajak neto
 * (penambahan - potongan) supaya cetakan dan data lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->decimal('tax_addition_amount', 18, 2)->default(0)->after('tax_amount');
            $table->decimal('tax_deduction_amount', 18, 2)->default(0)->after('tax_addition_amount');
            $table->decimal('discount_amount', 18, 2)->default(0)->after('tax_deduction_amount');
            $table->decimal('shipping_amount', 18, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'tax_addition_amount',
                'tax_deduction_amount',
                'discount_amount',
                'shipping_amount',
            ]);
        });
    }
};
