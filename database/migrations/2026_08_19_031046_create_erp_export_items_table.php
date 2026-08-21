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
        Schema::create('erp_export_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('erp_export_batch_id')->constrained('erp_export_batches')->onDelete('cascade');
            $table->foreignId('payment_slip_id')->unique()->constrained('payment_slips')->onDelete('restrict');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_export_items');
    }
};
