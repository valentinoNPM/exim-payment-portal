<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\ListPaymentSlips;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\Buyer;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentSlipAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['maker', 'checker', 'approver'] as $role) {
            Role::create(['name' => $role]);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_maker_only_sees_and_opens_own_payment_slips(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $otherMaker = User::factory()->create()->assignRole('maker');
        $ownSlip = $this->createSlip('PS-OWN', $maker);
        $otherSlip = $this->createSlip('PS-OTHER', $otherMaker);

        $this->actingAs($maker);

        Livewire::test(ListPaymentSlips::class)
            ->assertCanSeeTableRecords([$ownSlip])
            ->assertCanNotSeeTableRecords([$otherSlip])
            ->assertSee('Buyer: Buyer PS-OWN');

        $this->get(PaymentSlipResource::getUrl('view', ['record' => $ownSlip]))->assertOk();
        $this->get(PaymentSlipResource::getUrl('view', ['record' => $otherSlip]))->assertNotFound();
        $this->assertTrue(PaymentSlipResource::canEdit($ownSlip));
        $this->assertFalse(PaymentSlipResource::canEdit($otherSlip));
        $this->assertTrue(PaymentSlipResource::canSubmit($ownSlip));
        $this->assertFalse(PaymentSlipResource::canSubmit($otherSlip));
        $this->assertTrue(PaymentSlipResource::canDelete($ownSlip));
        $this->assertFalse(PaymentSlipResource::canDelete($otherSlip));
        $this->assertTrue(PaymentSlipResource::canDeleteAny());
    }

    public function test_checker_sees_and_opens_all_payment_slips(): void
    {
        $firstMaker = User::factory()->create()->assignRole('maker');
        $secondMaker = User::factory()->create()->assignRole('maker');
        $checker = User::factory()->create()->assignRole('checker');
        $firstSlip = $this->createSlip('PS-FIRST', $firstMaker);
        $secondSlip = $this->createSlip('PS-SECOND', $secondMaker, 'submitted');

        $this->actingAs($checker);

        Livewire::test(ListPaymentSlips::class)
            ->assertCanSeeTableRecords([$firstSlip, $secondSlip]);

        $this->get(PaymentSlipResource::getUrl('view', ['record' => $firstSlip]))->assertOk();
        $this->get(PaymentSlipResource::getUrl('view', ['record' => $secondSlip]))->assertOk();
        $this->assertTrue(PaymentSlipResource::canEdit($firstSlip));
        $this->assertTrue(PaymentSlipResource::canEdit($secondSlip));
        $this->assertFalse(PaymentSlipResource::canDeleteAny());
    }

    public function test_checker_role_takes_precedence_for_a_user_who_is_also_a_maker(): void
    {
        $owner = User::factory()->create()->assignRole('maker');
        $checkerMaker = User::factory()->create()->assignRole(['maker', 'checker']);
        $slip = $this->createSlip('PS-MULTI-ROLE', $owner);

        $this->actingAs($checkerMaker);

        Livewire::test(ListPaymentSlips::class)->assertCanSeeTableRecords([$slip]);
        $this->assertTrue(PaymentSlipResource::canView($slip));
        $this->assertFalse(PaymentSlipResource::canSubmit($slip));
    }

    private function createSlip(string $number, User $creator, string $status = 'draft'): PaymentSlip
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
            'transaction_type' => 'export',
            'supplier_id' => $supplier->id,
            'buyer_id' => $buyer->id,
            'status' => $status,
            'created_by' => $creator->id,
        ]);
    }
}
