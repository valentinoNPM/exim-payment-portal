<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('taxes')->insertOrIgnore([
            [
                'code' => 'PPH-4-2-JK-1.75',
                'name' => 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 1,75%',
                'rate' => 1.7500,
                'calculation_type' => 'deduction',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PPH-4-2-JK-2.65',
                'name' => 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 2,65%',
                'rate' => 2.6500,
                'calculation_type' => 'deduction',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PPH-4-2-JK-3.5',
                'name' => 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 3,5%',
                'rate' => 3.5000,
                'calculation_type' => 'deduction',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PPH-4-2-JK-4',
                'name' => 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 4%',
                'rate' => 4.0000,
                'calculation_type' => 'deduction',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PPH-4-2-JK-6',
                'name' => 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 6%',
                'rate' => 6.0000,
                'calculation_type' => 'deduction',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('taxes')->whereIn('code', [
            'PPH-4-2-JK-1.75',
            'PPH-4-2-JK-2.65',
            'PPH-4-2-JK-3.5',
            'PPH-4-2-JK-4',
            'PPH-4-2-JK-6',
        ])->delete();
    }
};
