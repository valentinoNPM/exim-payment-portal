<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('company_code', 20);
            $table->string('scope_key', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_code', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_sequences');
    }
};
