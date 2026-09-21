<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('tax_calculation_mode', 20)->nullable()->after('vat_invoice_number');
        });

        DB::table('invoices')->update(['tax_calculation_mode' => 'invoice_legacy']);
        DB::table('invoices')
            ->whereIn('payment_slip_id', DB::table('payment_slips')
                ->select('id')
                ->where('transaction_type', 'import')
                ->orWhere('tax_calculation_mode', 'itemized'))
            ->update(['tax_calculation_mode' => 'itemized']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('tax_calculation_mode');
        });
    }
};
