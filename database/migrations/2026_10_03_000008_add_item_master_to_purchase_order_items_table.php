<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->foreignId('item_id')->nullable()->after('line_number')->constrained('items')->nullOnDelete();
            $table->string('item_code_snapshot')->nullable()->after('item_id');
            $table->string('item_name_snapshot')->nullable()->after('item_code_snapshot');
            $table->text('specification_snapshot')->nullable()->after('item_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('item_id');
            $table->dropColumn([
                'item_code_snapshot',
                'item_name_snapshot',
                'specification_snapshot',
            ]);
        });
    }
};
