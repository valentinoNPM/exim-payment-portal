<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->foreignId('ppn_tax_id')->nullable()->after('tax_addition_amount')->constrained('taxes')->restrictOnDelete();
            $table->foreignId('pph_tax_id')->nullable()->after('tax_deduction_amount')->constrained('taxes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ppn_tax_id');
            $table->dropConstrainedForeignId('pph_tax_id');
        });
    }
};
