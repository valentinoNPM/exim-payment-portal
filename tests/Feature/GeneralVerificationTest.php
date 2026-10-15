<?php

namespace Tests\Feature;

use App\Actions\ExportPaymentSlipToErp;
use App\Actions\VerifyPaymentSlip;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentSlips\CreatePaymentSlip;
use App\Services\PaymentSlips\SavePaymentSlipEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GeneralVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::create(['name' => 'checker']);
        $this->checker = User::factory()->create()->assignRole('checker');
        $this->actingAs($this->checker);
    }

    private function slip(string $currency = 'IDR', float $discount = 10, float $shipping = 20): PaymentSlip
    {
        $supplier = Supplier::create(['code' => 'QA-'.Supplier::count(), 'name' => 'General Supplier']);
        $slip = app(CreatePaymentSlip::class)->execute('general', [
            'supplier_id' => $supplier->id, 'currency' => $currency, 'transaction_description' => 'General QA',
            'invoices' => array_map(fn ($index) => [
                'invoice_number' => 'INV-QA-'.$index, 'invoice_date' => '2026-10-15', 'tax_calculation_mode' => 'itemized',
                'discount_amount' => $discount, 'shipping_amount' => $shipping,
                'items' => [['item_name' => 'Barang QA', 'quantity' => 2, 'unit_price_amount' => 100.25]],
            ], [1, 2]),
        ], app(SavePaymentSlipEdit::class));
        $slip->update(['status' => 'submitted']);

        return $slip->fresh();
    }

    public function test_general_verifies_each_currency_and_adjustments_without_erp_requirements(): void
    {
        foreach (['IDR', 'USD', 'EUR'] as $currency) {
            foreach ([[0, 0], [10, 0], [0, 20], [10, 20]] as [$discount, $shipping]) {
                $slip = $this->slip($currency, $discount, $shipping);
                $before = $slip->only(['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount']);
                app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
                $this->assertSame('approved', $slip->status);
                $this->assertSame($before, $slip->only(array_keys($before)));
                $this->assertSame(1, $slip->audits()->where('event', 'verified')->count());
            }
        }
    }

    public function test_invalid_totals_and_adjustments_are_rejected_atomically(): void
    {
        foreach (['grand_total_amount' => 999, 'tax_addition_amount' => 5, 'discount_amount' => -1] as $field => $value) {
            $slip = $this->slip();
            DB::table('invoices')->where('payment_slip_id', $slip->id)->update([$field => $value]);
            try {
                app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
                $this->fail('Inconsistent invoice must be rejected.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
            $this->assertSame('submitted', $slip->fresh()->status);
            $this->assertNull($slip->fresh()->approved_at);
            $this->assertSame(0, $slip->audits()->where('event', 'verified')->count());
        }
    }

    public function test_adjusted_general_is_approved_but_export_is_safely_blocked(): void
    {
        foreach ([[10, 0], [0, 20], [10, 20]] as [$discount, $shipping]) {
            $slip = $this->slip('IDR', $discount, $shipping);
            app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
            try {
                app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
                $this->fail('Unsupported journal must not be created.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('diskon/ongkir', $e->getMessage());
            }
            $this->assertSame('approved', $slip->fresh()->status);
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertDatabaseCount('erp_export_items', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('erp-exports'));
        }
    }

    public function test_historical_negative_item_can_verify_without_becoming_a_second_discount(): void
    {
        $slip = $this->slip('IDR', 0, 0);
        $invoice = $slip->invoices()->first();
        $invoice->items()->create(['line_number' => 2, 'item_name' => 'Diskon pembelian', 'quantity' => 1, 'unit_price_amount' => -10]);
        $before = $slip->fresh()->grand_total_amount;
        app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
        $this->assertSame($before, $slip->grand_total_amount);
        $this->assertSame('0.00', $invoice->fresh()->discount_amount);
        $this->expectExceptionMessage('item negatif');
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
    }
}
