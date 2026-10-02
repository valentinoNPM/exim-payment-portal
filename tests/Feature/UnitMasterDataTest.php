<?php

namespace Tests\Feature;

use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Unit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UnitMasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        $this->actingAs(User::factory()->create()->assignRole('maker'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_unit_master_data_can_be_listed_created_and_edited(): void
    {
        $this->get(UnitResource::getUrl('index'))->assertOk();

        Livewire::test(CreateUnit::class)
            ->fillForm([
                'code' => 'PCS',
                'name' => 'Pieces',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $unit = Unit::query()->where('code', 'PCS')->firstOrFail();

        Livewire::test(EditUnit::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['name' => 'Piece'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('units', [
            'code' => 'PCS',
            'name' => 'Piece',
            'is_active' => true,
        ]);
    }
}
