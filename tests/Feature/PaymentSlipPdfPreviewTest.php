<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentSlipPdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['maker', 'checker', 'approver'] as $role) {
            Role::create(['name' => $role]);
        }
    }

    public function test_guest_cannot_preview_a_payment_slip_pdf(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $slip = $this->createSlip('PS-PREVIEW-GUEST', $maker);

        $this->getJson(route('payment-slips.pdf.preview', $slip))
            ->assertUnauthorized();
    }

    public function test_maker_can_preview_own_payment_slip_pdf_inline(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $slip = $this->createSlip('PS-PREVIEW-OWN', $maker);

        $response = $this->actingAs($maker)
            ->get(route('payment-slips.pdf.preview', $slip));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename=payment-slip-PS-PREVIEW-OWN.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_maker_cannot_preview_another_makers_payment_slip_pdf(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $otherMaker = User::factory()->create()->assignRole('maker');
        $slip = $this->createSlip('PS-PREVIEW-OTHER', $otherMaker);

        $this->actingAs($maker)
            ->get(route('payment-slips.pdf.preview', $slip))
            ->assertForbidden();
    }

    public function test_checker_can_preview_any_payment_slip_pdf(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $checker = User::factory()->create()->assignRole('checker');
        $slip = $this->createSlip('PS-PREVIEW-CHECKER', $maker);

        $this->actingAs($checker)
            ->get(route('payment-slips.pdf.preview', $slip))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function createSlip(string $number, User $creator): PaymentSlip
    {
        $supplier = Supplier::create([
            'code' => 'SUP-'.$number,
            'name' => 'Supplier '.$number,
        ]);
        $buyer = Buyer::create([
            'code' => 'BUY-'.$number,
            'name' => 'Buyer '.$number,
        ]);

        return PaymentSlip::create([
            'slip_number' => $number,
            'transaction_type' => PaymentSlip::TYPE_EXPORT,
            'supplier_id' => $supplier->id,
            'buyer_id' => $buyer->id,
            'status' => 'draft',
            'created_by' => $creator->id,
        ]);
    }
}
