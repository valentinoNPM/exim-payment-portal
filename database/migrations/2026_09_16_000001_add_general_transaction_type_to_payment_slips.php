<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_slips MODIFY transaction_type ENUM('import', 'export', 'general') NOT NULL");

            return;
        }

        Schema::table('payment_slips', function (Blueprint $table): void {
            $table->string('transaction_type')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payment_slips MODIFY transaction_type ENUM('import', 'export') NOT NULL");
        }
    }
};
