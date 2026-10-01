<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerMasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        $this->actingAs(User::factory()->create()->assignRole('maker'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_customer_master_data_can_be_listed_created_and_edited(): void
    {
        $this->get(CustomerResource::getUrl('index'))->assertOk();

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'code' => 'CUST-001',
                'name' => 'PT Customer Satu',
                'address' => 'Jakarta',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('code', 'CUST-001')->firstOrFail();

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['name' => 'PT Customer Utama'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('customers', [
            'code' => 'CUST-001',
            'name' => 'PT Customer Utama',
            'is_active' => true,
        ]);
    }
}
