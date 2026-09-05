<?php

namespace Tests\Feature;

use Database\Seeders\ErpMasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpMasterDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_erp_master_data_idempotently(): void
    {
        $this->seed(ErpMasterDataSeeder::class);
        $this->seed(ErpMasterDataSeeder::class);

        foreach (['43011011', '43011015', '43011021', '43011022', '43011099', '51071409', '51071423', '51071424', '51071427', '51071430', '51071499'] as $code) {
            $this->assertDatabaseHas('chart_of_accounts', [
                'code' => $code,
                'is_active' => true,
            ]);
        }

        $this->assertDatabaseCount('chart_of_accounts', 11);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertSame('I05_190000', config('erp-export.cost_centers.import'));
        $this->assertSame('I05_902020', config('erp-export.cost_centers.export'));
    }
}
