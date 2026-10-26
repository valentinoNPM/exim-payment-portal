<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('signer_pic')->nullable();
            $table->string('signer_area_manager')->nullable();
            $table->string('signer_factory_manager')->nullable();
            $table->string('signer_director')->nullable();
            $table->string('signer_ga')->nullable();
            $table->string('signer_manager')->nullable();
            $table->string('signer_ga_director')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'signer_pic',
                'signer_area_manager',
                'signer_factory_manager',
                'signer_director',
                'signer_ga',
                'signer_manager',
                'signer_ga_director',
            ]);
        });
    }
};
