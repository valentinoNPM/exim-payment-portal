<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ErpMasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $chartOfAccounts = [
            ['code' => '43011011', 'name' => 'Beban ekspor (forwarder)'],
            ['code' => '43011015', 'name' => 'Beban ekspor (container lift on/off)'],
            ['code' => '43011021', 'name' => 'Beban ekspor (trucking)'],
            ['code' => '43011022', 'name' => 'Beban ekspor (courier)'],
            ['code' => '43011099', 'name' => 'Beban ekspor (lainnya)'],
            ['code' => '51071409', 'name' => 'Beban transportasi dan pergudangan (import duty)'],
            ['code' => '51071423', 'name' => 'Beban transportasi dan pergudangan (container lift on)'],
            ['code' => '51071424', 'name' => 'Beban transportasi dan pergudangan (import transportation)'],
            ['code' => '51071427', 'name' => 'Beban transportasi dan pergudangan (lokal) non style'],
            ['code' => '51071430', 'name' => 'Beban transportasi dan pergudangan (gudang) non style'],
            ['code' => '51071499', 'name' => 'Beban transportasi dan pergudangan (lainnya)'],
        ];

        foreach ($chartOfAccounts as $chartOfAccount) {
            ChartOfAccount::query()->updateOrCreate(
                ['code' => $chartOfAccount['code']],
                ['name' => $chartOfAccount['name'], 'is_active' => true],
            );
        }

    }
}
