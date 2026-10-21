<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->string('approval_passed_1')->nullable();
            $table->string('approval_passed_2')->nullable();
            $table->string('approval_passed_3')->nullable();
            $table->string('approval_director')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->dropColumn(['approval_passed_1', 'approval_passed_2', 'approval_passed_3', 'approval_director']);
        });
    }
};
