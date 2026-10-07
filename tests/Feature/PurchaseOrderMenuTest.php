<?php

namespace Tests\Feature;

use App\Filament\Pages\PoReport;
use App\Filament\Pages\PoSlips;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Division;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Menu sisi kiri grup "Purchase Order": Daftar PO, New Purchase Order, dan Laporan PO.
 */
class PurchaseOrderMenuTest extends TestCase
{
    use RefreshDatabase;

    protected User $gaMaker;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        Role::create(['name' => 'checker']);

        $ga = Division::create([
            'code' => 'GA',
            'name' => 'General Affairs',
            'is_active' => true,
        ]);

        $this->gaMaker = User::factory()->create([
            'division_id' => $ga->id,
        ])->assignRole('maker');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_grup_purchase_order_berisi_tiga_tautan_dengan_label_yang_benar(): void
    {
        $this->actingAs($this->gaMaker);

        $tautan = [
            'Daftar PO' => PurchaseOrderResource::class,
            'PO Baru' => CreatePurchaseOrder::class,
            'Laporan PO' => PoReport::class,
        ];

        foreach ($tautan as $label => $kelas) {
            $this->assertSame('Purchase Order', $kelas::getNavigationGroup(), "Grup untuk {$label} salah.");
            $this->assertSame($label, $kelas::getNavigationLabel(), "Label {$label} salah.");
        }
    }

    public function test_slip_po_disembunyikan_dari_menu(): void
    {
        $this->assertFalse(
            PoSlips::shouldRegisterNavigation(),
            'Slip PO tidak boleh muncul di menu karena isinya sama dengan Daftar PO.'
        );
    }

    public function test_po_baru_terdaftar_di_menu(): void
    {
        $this->actingAs($this->gaMaker);

        $this->assertTrue(
            CreatePurchaseOrder::shouldRegisterNavigation(),
            'Halaman PO Baru harus muncul di menu.'
        );

        $items = collect(PurchaseOrderResource::getNavigationItems());

        $this->assertSame(
            ['New Purchase Order', 'Daftar PO'],
            $items->map(fn ($item): string => $item->getLabel())->values()->all(),
        );
        $this->assertSame(
            PurchaseOrderResource::getUrl('create'),
            $items->first()->getUrl(),
        );
    }

    public function test_menu_new_purchase_order_tidak_diberikan_ke_non_ga(): void
    {
        $hr = Division::create(['code' => 'HR', 'name' => 'Human Resources', 'is_active' => true]);
        $hrMaker = User::factory()->create(['division_id' => $hr->id])->assignRole('maker');

        $this->actingAs($hrMaker);

        $labels = collect(PurchaseOrderResource::getNavigationItems())
            ->map(fn ($item): string => $item->getLabel())
            ->all();

        $this->assertNotContains('New Purchase Order', $labels);
    }

    public function test_urutan_menu_po_baru_slip_laporan(): void
    {
        $this->actingAs($this->gaMaker);

        $this->assertSame(2, PurchaseOrderResource::getNavigationSort());
        $this->assertSame(1, CreatePurchaseOrder::getNavigationSort());
        $this->assertSame(3, PoSlips::getNavigationSort());
        $this->assertSame(4, PoReport::getNavigationSort());
    }

    public function test_halaman_slip_po_dan_laporan_po_terbuka(): void
    {
        $this->actingAs($this->gaMaker);

        $this->get(PoSlips::getUrl())->assertOk();
        $this->get(PoReport::getUrl())->assertOk();
    }

    public function test_halaman_po_tertutup_untuk_divisi_lain(): void
    {
        $hr = Division::create(['code' => 'HR', 'name' => 'Human Resources', 'is_active' => true]);
        $hrMaker = User::factory()->create(['division_id' => $hr->id])->assignRole('maker');

        $this->actingAs($hrMaker);

        $this->get(PoSlips::getUrl())->assertForbidden();
        $this->get(PoReport::getUrl())->assertForbidden();
    }
}
