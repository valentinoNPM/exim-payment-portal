<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('ppn_tax_id')->nullable()->after('coa_id')->constrained('taxes')->onDelete('set null');
            $table->foreignId('pph_tax_id')->nullable()->after('ppn_tax_id')->constrained('taxes')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['ppn_tax_id']);
            $table->dropForeign(['pph_tax_id']);
            $table->dropColumn(['ppn_tax_id', 'pph_tax_id']);
        });
    }
};
