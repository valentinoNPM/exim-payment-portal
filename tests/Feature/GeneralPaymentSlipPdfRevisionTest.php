<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\CreateGeneralPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Models\Customer;
use App\Models\Division;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class GeneralPaymentSlipPdfRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        Role::create(['name' => 'checker']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_general_form_requires_and_stores_transaction_description(): void
    {
        $division = Division::create(['code' => 'HR', 'name' => 'Human Resources', 'is_active' => true]);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'GEN-DESC', 'name' => 'General Supplier', 'is_active' => true]);
        $customer = Customer::create(['code' => 'CUST-GEN', 'name' => 'General Customer', 'is_active' => true]);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $this->actingAs($maker);

        $formData = [
            'supplier_id' => $supplier->id,
            'invoices' => [[
                'invoice_number' => 'NOTA-GEN-001',
                'invoice_date' => '2026-09-29',
                'items' => [[
                    'item_name' => 'Biaya operasional',
                    'quantity' => 2,
                    'unit_id' => $unit->id,
                    'unit_price_amount' => 50000,
                ]],
            ]],
        ];

        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm($formData)
            ->call('create')
            ->assertHasFormErrors(['transaction_description' => 'required']);

        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                ...$formData,
                'transaction_description' => 'Biaya operasional kantor',
                'customer_id' => $customer->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('payment_slips', [
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'transaction_description' => 'Biaya operasional kantor',
            'customer_id' => $customer->id,
        ]);

        $slip = PaymentSlip::query()->where('customer_id', $customer->id)->firstOrFail();

        Livewire::test(ViewPaymentSlip::class, ['record' => $slip->getRouteKey()])
            ->assertFormSet(['customer_id' => $customer->id]);

        $slip->invoices()->firstOrFail()->items()->update(['unit_id' => null]);

        Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()])
            ->assertFormSet(['customer_id' => $customer->id])
            ->fillForm(['customer_id' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($slip->fresh()->customer_id);
        $this->assertNull($slip->invoices()->firstOrFail()->items()->firstOrFail()->unit_id);
    }

    public function test_general_pdf_uses_description_and_separate_quantity_column(): void
    {
        $checker = User::factory()->create()->assignRole('checker');
        $customer = Customer::create(['code' => 'PDF-CUST', 'name' => 'PT Customer PDF', 'is_active' => true]);
        $unit = Unit::create(['code' => 'BOX', 'name' => 'Box', 'is_active' => true]);
        $slip = ErpPaymentSlip::create($checker);
        $slip->update([
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'transaction_description' => 'Biaya operasional kantor',
            'customer_id' => $customer->id,
            'buyer_id' => null,
        ]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->items()->delete();
        $invoice->items()->create([
            'item_name' => 'Kertas A4',
            'quantity' => 3,
            'unit_id' => $unit->id,
            'unit_price_amount' => 53210,
        ]);
        $slip->load(['supplier', 'customer', 'buyer', 'invoices.buyer', 'invoices.items.unit', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('PAYMENT/INCOME SLIP', $html);
        $this->assertStringContainsString('CHARGE BIAYA OPERASIONAL KANTOR', $html);
        $this->assertStringContainsString('PT Customer PDF', $html);
        $this->assertStringContainsString('>Quantity</th>', $html);
        $this->assertStringContainsString('>Satuan</th>', $html);
        $this->assertStringContainsString('>Box</td>', $html);
        $this->assertMatchesRegularExpression('/<td style="text-align: center;">3<\/td>/', $html);
        $this->assertStringNotContainsString('Qty: 3', $html);
        $this->assertStringNotContainsString('Ref:', $html);
    }

    public function test_legacy_general_pdf_keeps_charge_lain_lain_fallback(): void
    {
        $checker = User::factory()->create()->assignRole('checker');
        $slip = ErpPaymentSlip::create($checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL, 'buyer_id' => null]);
        $slip->load(['supplier', 'customer', 'buyer', 'invoices.buyer', 'invoices.items.unit', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('CHARGE LAIN-LAIN', $html);
        $this->assertStringContainsString('>Satuan</th>', $html);
        $this->assertStringNotContainsString('<td class="data-label">Customer</td>', $html);
    }
}
