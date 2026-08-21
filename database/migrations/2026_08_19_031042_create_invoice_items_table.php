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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->unsignedInteger('line_number');
            $table->string('item_name');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price_amount', 18, 2);
            $table->decimal('subtotal_amount', 18, 2);
            $table->foreignId('coa_id')->nullable()->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('coa_code_snapshot')->nullable();
            $table->string('coa_name_snapshot')->nullable();
            $table->decimal('tax_addition_amount', 18, 2)->default(0.00);
            $table->decimal('tax_deduction_amount', 18, 2)->default(0.00);
            $table->decimal('net_amount', 18, 2); // subtotal + tax_addition - tax_deduction
            $table->timestamps();

            // Unique composite index
            $table->unique(['invoice_id', 'line_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
