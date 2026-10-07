<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->string('tax_code_snapshot');
            $table->string('tax_name_snapshot');
            $table->decimal('rate_snapshot', 8, 4);
            $table->enum('calculation_type_snapshot', ['addition', 'deduction']);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'tax_id']);
        });

        Schema::create('purchase_order_item_taxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->restrictOnDelete();
            $table->string('tax_code_snapshot');
            $table->string('tax_name_snapshot');
            $table->decimal('rate_snapshot', 8, 4);
            $table->enum('calculation_type_snapshot', ['addition', 'deduction']);
            $table->decimal('taxable_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->timestamps();

            $table->unique(['purchase_order_item_id', 'tax_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_taxes');
        Schema::dropIfExists('purchase_order_taxes');
    }
};
