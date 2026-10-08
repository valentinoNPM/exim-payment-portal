<?php

namespace Tests\Feature;

use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\Division;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WarehouseResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        $division = Division::create([
            'code' => 'GA',
            'name' => 'General Affairs',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['division_id' => $division->id])->assignRole('maker');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);
    }

    public function test_master_gudang_is_available_in_admin_navigation_and_can_be_created(): void
    {
        $this->get(WarehouseResource::getUrl('index'))->assertOk();

        Livewire::test(CreateWarehouse::class)
            ->fillForm([
                'source_code' => 'WH-TEST',
                'name' => 'Gudang Baru',
                'type' => 'Gudang',
                'branch' => 'PT HANSOLL INDO JAVA',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('warehouses', [
            'source_code' => 'WH-TEST',
            'name' => 'Gudang Baru',
            'is_active' => true,
        ]);
    }

    public function test_master_gudang_list_shows_ecount_location_data(): void
    {
        $warehouse = Warehouse::create([
            'source_code' => 'WH-LIST',
            'name' => 'Warehouse List',
            'type' => 'Gudang',
            'branch' => 'PT HANSOLL INDO JAVA',
            'is_active' => true,
        ]);

        Livewire::test(ListWarehouses::class)
            ->searchTable('WH-LIST')
            ->assertCanSeeTableRecords([$warehouse])
            ->assertSee('WH-LIST')
            ->assertSee('Warehouse List');
    }

    public function test_only_ga_maker_can_access_and_mutate_master_gudang(): void
    {
        $warehouse = Warehouse::create([
            'source_code' => 'WH-AUTH',
            'name' => 'Warehouse Authorization',
            'is_active' => true,
        ]);
        $hrDivision = Division::create([
            'code' => 'HR',
            'name' => 'Human Resources',
            'is_active' => true,
        ]);
        $hrMaker = User::factory()->create(['division_id' => $hrDivision->id])->assignRole('maker');

        $this->actingAs($hrMaker);

        $this->assertFalse(WarehouseResource::canAccess());
        $this->assertFalse(WarehouseResource::canCreate());
        $this->assertFalse(WarehouseResource::canEdit($warehouse));
        $this->assertFalse(WarehouseResource::canDelete($warehouse));
        $this->assertFalse(WarehouseResource::canDeleteAny());
        $this->get(WarehouseResource::getUrl('index'))->assertForbidden();
    }

    public function test_source_code_is_preserved_when_editing_a_warehouse(): void
    {
        $warehouse = Warehouse::create([
            'source_code' => 'WH-LOCKED',
            'name' => 'Warehouse Before Edit',
            'type' => 'Gudang',
            'is_active' => true,
        ]);

        Livewire::test(EditWarehouse::class, ['record' => $warehouse->getRouteKey()])
            ->fillForm([
                'source_code' => 'WH-CHANGED',
                'name' => 'Warehouse After Edit',
                'type' => 'Gudang',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $warehouse->refresh();

        $this->assertSame('WH-LOCKED', $warehouse->source_code);
        $this->assertSame('Warehouse After Edit', $warehouse->name);
    }
}
