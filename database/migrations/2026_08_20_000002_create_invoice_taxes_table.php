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
        Schema::create('invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->onDelete('cascade');
            $table->foreignId('tax_id')->constrained('taxes')->onDelete('restrict');
            $table->string('tax_code_snapshot');
            $table->string('tax_name_snapshot');
            $table->decimal('rate_snapshot', 8, 4);
            $table->enum('calculation_type_snapshot', ['addition', 'deduction']);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();

            // Unique composite index
            $table->unique(['invoice_id', 'tax_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_taxes');
    }
};
