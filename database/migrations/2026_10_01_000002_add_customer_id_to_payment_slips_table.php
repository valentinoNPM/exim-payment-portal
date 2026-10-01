<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('customers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
