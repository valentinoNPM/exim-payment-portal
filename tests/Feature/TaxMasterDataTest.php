<?php

namespace Tests\Feature;

use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_pph_final_4_2_construction_rates_are_available_as_active_deductions(): void
    {
        $taxes = Tax::query()
            ->whereIn('code', [
                'PPH-4-2-JK-1.75',
                'PPH-4-2-JK-2.65',
                'PPH-4-2-JK-3.5',
                'PPH-4-2-JK-4',
                'PPH-4-2-JK-6',
            ])
            ->orderBy('rate')
            ->get();

        $this->assertSame([
            ['PPH-4-2-JK-1.75', 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 1,75%', '1.7500'],
            ['PPH-4-2-JK-2.65', 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 2,65%', '2.6500'],
            ['PPH-4-2-JK-3.5', 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 3,5%', '3.5000'],
            ['PPH-4-2-JK-4', 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 4%', '4.0000'],
            ['PPH-4-2-JK-6', 'PPh Final Pasal 4 Ayat (2) Jasa Konstruksi 6%', '6.0000'],
        ], $taxes->map(fn (Tax $tax): array => [$tax->code, $tax->name, $tax->rate])->all());
        $this->assertTrue($taxes->every(fn (Tax $tax): bool => $tax->calculation_type === 'deduction'));
        $this->assertTrue($taxes->every(fn (Tax $tax): bool => $tax->is_active));
    }
}
