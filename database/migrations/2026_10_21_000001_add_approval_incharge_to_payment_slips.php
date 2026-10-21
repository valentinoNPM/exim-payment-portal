<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->string('approval_incharge')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->dropColumn('approval_incharge');
        });
    }
};
