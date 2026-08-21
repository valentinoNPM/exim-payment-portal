<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('coa_code_snapshot')->nullable();
            $table->string('coa_name_snapshot')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['coa_id']);
            $table->dropColumn(['coa_id', 'coa_code_snapshot', 'coa_name_snapshot']);
        });
    }
};
