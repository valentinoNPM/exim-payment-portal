<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')
            ->select(['id', 'coa_id', 'coa_code_snapshot', 'coa_name_snapshot'])
            ->where(function ($query): void {
                $query->whereNotNull('coa_id')
                    ->orWhereNotNull('coa_code_snapshot')
                    ->orWhereNotNull('coa_name_snapshot');
            })
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    DB::table('invoice_items')
                        ->where('invoice_id', $invoice->id)
                        ->whereNull('coa_id')
                        ->update([
                            'coa_id' => $invoice->coa_id,
                            'coa_code_snapshot' => $invoice->coa_code_snapshot,
                            'coa_name_snapshot' => $invoice->coa_name_snapshot,
                        ]);
                }
            });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('vat_invoice_number')->nullable()->after('invoice_date');
            $table->dropForeign(['coa_id']);
            $table->dropColumn(['coa_id', 'coa_code_snapshot', 'coa_name_snapshot']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('coa_code_snapshot')->nullable();
            $table->string('coa_name_snapshot')->nullable();
            $table->dropColumn('vat_invoice_number');
        });
    }
};
