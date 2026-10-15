<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ListPaymentSlips;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
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
            ->assertTableColumnExists('slip_number')
            ->assertTableColumnDoesNotExist('id')
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

    public function test_edit_page_redirects_with_a_notification_when_edit_access_is_revoked_mid_request(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $slip = $this->createSlip('PS-EDIT-REVOKED', $maker);

        $this->actingAs($maker);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()]);

        PaymentSlip::query()->whereKey($slip)->update(['status' => 'approved']);

        $page
            ->set('data.transaction_description', 'SHOULD-NOT-BE-SAVED')
            ->assertRedirect(PaymentSlipResource::getUrl('view', ['record' => $slip]))
            ->assertNotified('Slip tidak dapat diedit lagi');

        $this->assertSame('approved', $slip->fresh()->status);
        $this->assertNull($slip->fresh()->transaction_description);
    }

    public function test_view_edit_action_visibility_follows_edit_permission(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $draft = $this->createSlip('PS-VIEW-DRAFT', $maker);
        $approved = $this->createSlip('PS-VIEW-APPROVED', $maker, 'approved');
        $exported = $this->createSlip('PS-VIEW-EXPORTED', $maker, 'exported');

        $this->actingAs($maker);

        Livewire::test(ViewPaymentSlip::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('edit');
        Livewire::test(ViewPaymentSlip::class, ['record' => $approved->getRouteKey()])
            ->assertActionHidden('edit');
        Livewire::test(ViewPaymentSlip::class, ['record' => $exported->getRouteKey()])
            ->assertActionHidden('edit');

        Livewire::test(ListPaymentSlips::class)
            ->assertTableActionVisible('edit', $draft)
            ->assertTableActionHidden('edit', $approved)
            ->assertTableActionHidden('edit', $exported);
    }

    public function test_checker_can_choose_edit_or_check_for_submitted_slip(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $checker = User::factory()->create()->assignRole('checker');
        $submitted = $this->createSlip('PS-CHECK-SUBMITTED', $maker, 'submitted');

        $this->actingAs($checker);

        Livewire::test(ViewPaymentSlip::class, ['record' => $submitted->getRouteKey()])
            ->assertActionVisible('check_slip')
            ->assertActionHasUrl('check_slip', route('payment-slips.checker-edit', $submitted))
            ->assertActionVisible('edit');

        Livewire::test(ListPaymentSlips::class)
            ->assertTableActionVisible('checker_edit', $submitted)
            ->assertTableActionVisible('edit', $submitted);
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
