<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentSlipTaxModeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_eight_historical_slip_and_invoice_tax_mode_groups_keep_their_own_calculation(): void
    {
        $supplier = Supplier::create(['code' => 'TAX-MODE', 'name' => 'Tax Mode Supplier']);
        $user = User::factory()->create();
        $ppn = Tax::create([
            'code' => 'PPN-MODE-10',
            'name' => 'PPN 10%',
            'rate' => 10,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);

        $groups = [
            [PaymentSlip::TYPE_GENERAL, PaymentSlip::TAX_MODE_INVOICE_LEGACY, PaymentSlip::TAX_MODE_ITEMIZED],
            [PaymentSlip::TYPE_GENERAL, PaymentSlip::TAX_MODE_INVOICE_LEGACY, PaymentSlip::TAX_MODE_INVOICE_LEGACY],
            [PaymentSlip::TYPE_IMPORT, PaymentSlip::TAX_MODE_ITEMIZED, PaymentSlip::TAX_MODE_ITEMIZED],
            [PaymentSlip::TYPE_EXPORT, PaymentSlip::TAX_MODE_INVOICE_LEGACY, PaymentSlip::TAX_MODE_INVOICE_LEGACY],
            [PaymentSlip::TYPE_EXPORT, PaymentSlip::TAX_MODE_ITEMIZED, PaymentSlip::TAX_MODE_ITEMIZED],
            [PaymentSlip::TYPE_EXPORT, PaymentSlip::TAX_MODE_ITEMIZED, PaymentSlip::TAX_MODE_INVOICE_LEGACY],
            [PaymentSlip::TYPE_IMPORT, PaymentSlip::TAX_MODE_INVOICE_LEGACY, PaymentSlip::TAX_MODE_ITEMIZED],
            [PaymentSlip::TYPE_IMPORT, PaymentSlip::TAX_MODE_ITEMIZED, PaymentSlip::TAX_MODE_INVOICE_LEGACY],
        ];

        foreach ($groups as $index => [$type, $slipMode, $invoiceMode]) {
            $slip = PaymentSlip::create([
                'slip_number' => 'PS-TAX-MODE-'.($index + 1),
                'transaction_type' => $type,
                'tax_calculation_mode' => $slipMode,
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
                'status' => 'draft',
            ]);

            // Raw inserts deliberately reproduce legacy combinations that the
            // current create hooks would otherwise normalize on new records.
            $invoiceId = DB::table('invoices')->insertGetId([
                'payment_slip_id' => $slip->id,
                'invoice_number' => 'INV-MODE-'.($index + 1),
                'invoice_date' => '2026-09-01',
                'tax_calculation_mode' => $invoiceMode,
                'ppn_tax_id' => $ppn->id,
                'subtotal_amount' => 9,
                'tax_addition_amount' => 0,
                'tax_deduction_amount' => 0,
                'grand_total_amount' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([1, 2] as $line) {
                DB::table('invoice_items')->insert([
                    'invoice_id' => $invoiceId,
                    'line_number' => $line,
                    'item_name' => 'Legacy line '.$line,
                    'quantity' => 1,
                    'unit_price_amount' => 4.50,
                    'subtotal_amount' => 4.50,
                    'tax_addition_amount' => 0,
                    'tax_deduction_amount' => 0,
                    'net_amount' => 4.50,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invoice = Invoice::findOrFail($invoiceId);
            $invoice->recalculateTotals();

            $this->assertSame($slipMode, $slip->fresh()->tax_calculation_mode, "Slip mode changed in group {$index}");
            $this->assertSame($invoiceMode, $invoice->fresh()->tax_calculation_mode, "Invoice mode changed in group {$index}");
            $this->assertSame($invoiceMode === PaymentSlip::TAX_MODE_ITEMIZED, $invoice->fresh()->usesItemizedTaxes());
            $this->assertSame($type === PaymentSlip::TYPE_IMPORT || $slipMode === PaymentSlip::TAX_MODE_ITEMIZED, $slip->fresh()->usesItemizedTaxes());
            $this->assertEqualsWithDelta($invoiceMode === PaymentSlip::TAX_MODE_ITEMIZED ? 9 : 10, (float) $invoice->fresh()->grand_total_amount, 0.001);
            $this->assertEqualsWithDelta((float) $invoice->fresh()->grand_total_amount, (float) $slip->fresh()->grand_total_amount, 0.001);
        }
    }
}
