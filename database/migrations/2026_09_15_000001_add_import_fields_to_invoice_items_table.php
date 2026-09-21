<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('source_supplier_name')->nullable()->after('item_name');
            $table->string('vat_invoice_number')->nullable()->after('source_supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['source_supplier_name', 'vat_invoice_number']);
        });
    }
};
