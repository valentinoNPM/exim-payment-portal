<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\ChartOfAccount;
use App\Models\Division;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Roles
        $makerRole = Role::firstOrCreate(['name' => 'maker']);
        $checkerRole = Role::firstOrCreate(['name' => 'checker']);
        $approverRole = Role::firstOrCreate(['name' => 'approver']);

        // 1b. Seed Divisions
        $divExim = Division::firstOrCreate(['code' => 'EXIM'], ['name' => 'Export-Import Division']);
        $divAcc = Division::firstOrCreate(['code' => 'ACC'], ['name' => 'Accounting & Finance']);
        $divMgmt = Division::firstOrCreate(['code' => 'MGMT'], ['name' => 'Management']);

        // 2. Create Default Users & Assign Roles + Divisions
        $makerUser = User::firstOrCreate(
            ['email' => 'maker@exim.com'],
            [
                'name' => 'Staff EXIM Maker',
                'password' => Hash::make('password'),
                'division_id' => $divExim->id,
            ]
        );
        $makerUser->assignRole($makerRole);
        if (! $makerUser->division_id) {
            $makerUser->update(['division_id' => $divExim->id]);
        }

        $checkerUser = User::firstOrCreate(
            ['email' => 'checker@exim.com'],
            [
                'name' => 'Staff Accounting Checker',
                'password' => Hash::make('password'),
                'division_id' => $divAcc->id,
            ]
        );
        $checkerUser->assignRole($checkerRole);
        if (! $checkerUser->division_id) {
            $checkerUser->update(['division_id' => $divAcc->id]);
        }

        $approverUser = User::firstOrCreate(
            ['email' => 'approver@exim.com'],
            [
                'name' => 'General Manager Approver',
                'password' => Hash::make('password'),
                'division_id' => $divMgmt->id,
            ]
        );
        $approverUser->assignRole($approverRole);
        if (! $approverUser->division_id) {
            $approverUser->update(['division_id' => $divMgmt->id]);
        }

        // 3. Seed Default Suppliers
        $suppliers = [
            ['code' => 'SUP-001', 'name' => 'PT Sumber Makmur', 'address' => 'Jl. Industri No. 12, Bekasi', 'is_active' => true],
            ['code' => 'SUP-002', 'name' => 'CV Maju Jaya', 'address' => 'Kawasan Industri Cikarang Blok B', 'is_active' => true],
            ['code' => 'SUP-003', 'name' => 'Global Trading Corp', 'address' => '123 Business Rd, Singapore', 'is_active' => true],
        ];
        foreach ($suppliers as $supplier) {
            Supplier::firstOrCreate(['code' => $supplier['code']], $supplier);
        }

        // 4. Seed Default Buyers
        $buyers = [
            ['code' => 'BUY-001', 'name' => 'PT Exim Distribution', 'address' => 'Jl. Gatot Subroto No. 45, Jakarta', 'is_active' => true],
            ['code' => 'BUY-002', 'name' => 'Indo Retail Corp', 'address' => 'Central Business District, Tangerang', 'is_active' => true],
        ];
        foreach ($buyers as $buyer) {
            Buyer::firstOrCreate(['code' => $buyer['code']], $buyer);
        }

        // 5. Seed Default Chart of Accounts (COA)
        $coas = [
            ['code' => '510100', 'name' => 'Bahan Baku Ekspor', 'is_active' => true],
            ['code' => '510200', 'name' => 'Biaya Logistik & Shipping', 'is_active' => true],
            ['code' => '510300', 'name' => 'Biaya Bea Masuk & Impor', 'is_active' => true],
            ['code' => '610100', 'name' => 'Biaya Administrasi Kantor', 'is_active' => true],
            ['code' => '610200', 'name' => 'Biaya Konsultan Pajak', 'is_active' => true],
        ];
        foreach ($coas as $coa) {
            ChartOfAccount::firstOrCreate(['code' => $coa['code']], $coa);
        }

        // 6. Seed Default Taxes (PPN 11%, PPh 23 2%)
        $taxes = [
            [
                'code' => 'PPN-11',
                'name' => 'PPN 11%',
                'rate' => 11.0000,
                'calculation_type' => 'addition',
                'is_active' => true,
            ],
            [
                'code' => 'PPH-23-2',
                'name' => 'PPh 23 (Jasa) 2%',
                'rate' => 2.0000,
                'calculation_type' => 'deduction',
                'is_active' => true,
            ],
        ];
        foreach ($taxes as $tax) {
            Tax::firstOrCreate(['code' => $tax['code']], $tax);
        }
    }
}
