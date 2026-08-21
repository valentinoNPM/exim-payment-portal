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
        Schema::create('payment_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_number')->unique();
            $table->enum('transaction_type', ['import', 'export']);
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('restrict');
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->onDelete('restrict');
            $table->enum('status', ['draft', 'submitted', 'pending_approval', 'approved', 'exported'])->default('draft');
            $table->decimal('subtotal_amount', 18, 2)->default(0.00);
            $table->decimal('tax_addition_amount', 18, 2)->default(0.00);
            $table->decimal('tax_deduction_amount', 18, 2)->default(0.00);
            $table->decimal('grand_total_amount', 18, 2)->default(0.00);
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_slips');
    }
};
