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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_slip_id')->constrained('payment_slips')->onDelete('cascade');
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->decimal('subtotal_amount', 18, 2)->default(0.00);
            $table->decimal('tax_addition_amount', 18, 2)->default(0.00);
            $table->decimal('tax_deduction_amount', 18, 2)->default(0.00);
            $table->decimal('grand_total_amount', 18, 2)->default(0.00);
            $table->foreignId('document_file_id')->nullable()->constrained('document_files')->onDelete('set null');
            $table->timestamps();

            // Unique composite index
            $table->unique(['payment_slip_id', 'invoice_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
