<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('shipping_amount', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['discount_amount', 'shipping_amount']));
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
        });
    }
};
