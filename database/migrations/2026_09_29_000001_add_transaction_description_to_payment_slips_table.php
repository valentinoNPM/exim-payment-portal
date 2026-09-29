<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->string('transaction_description')->nullable()->after('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->dropColumn('transaction_description');
        });
    }
};
