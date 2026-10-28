<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Models\Division;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderItemAddButtonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tombol "Tambah Item" harus berada di bawah tabel item, bukan di header seksi,
     * supaya pengisian banyak baris tidak memaksa pengguna menggulir ke atas.
     */
    public function test_add_item_button_sits_below_the_items_table(): void
    {
        Role::create(['name' => 'maker']);
        $division = Division::create(['code' => 'GA', 'name' => 'General Affairs', 'is_active' => true]);
        $user = User::factory()->create(['division_id' => $division->id])->assignRole('maker');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        $html = Livewire::test(CreatePurchaseOrder::class)->html();

        $this->assertSame(
            1,
            substr_count($html, 'data-add-row'),
            'Halaman harus memuat tepat satu tombol tambah item.'
        );

        $tablePosition = strpos($html, 'po-editor__table-wrap');
        $buttonPosition = strpos($html, 'data-add-row');

        $this->assertNotFalse($tablePosition, 'Wadah tabel item harus ada.');
        $this->assertNotFalse($buttonPosition, 'Tombol tambah item harus ada.');

        $this->assertGreaterThan(
            $tablePosition,
            $buttonPosition,
            'Tombol tambah item harus berada setelah wadah tabel item.'
        );

        $this->assertMatchesRegularExpression(
            '/<\/div>\s*<button[^>]*class="po-editor__add-button"[^>]*data-add-row/',
            $html,
            'Tombol tambah item harus menjadi elemen tepat setelah wadah tabel.'
        );

        $headerStart = strpos($html, 'po-items-heading');
        $headerEnd = strpos($html, '</header>', $headerStart);
        $this->assertNotFalse($headerStart, 'Judul seksi Item PO harus ada.');
        $this->assertNotFalse($headerEnd, 'Header seksi Item PO harus tertutup.');

        $itemsHeader = substr($html, $headerStart, $headerEnd - $headerStart);

        $this->assertStringNotContainsString(
            'data-add-row',
            $itemsHeader,
            'Header seksi Item PO tidak boleh lagi memuat tombol tambah item.'
        );
    }

    /**
     * Halaman ubah PO memakai view editor yang sama, jadi tombolnya juga harus di bawah tabel.
     */
    public function test_add_item_button_sits_below_the_table_on_the_edit_page(): void
    {
        Role::create(['name' => 'maker']);
        $division = Division::create(['code' => 'GA', 'name' => 'General Affairs', 'is_active' => true]);
        $user = User::factory()->create(['division_id' => $division->id])->assignRole('maker');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        $supplier = Supplier::create([
            'code' => 'SUP-TEST',
            'name' => 'PT Vendor Tombol',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'source_code' => 'TEST-WH',
            'name' => 'Gudang Tombol',
            'type' => 'Gudang',
            'is_active' => true,
        ]);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $item = Item::create([
            'name' => 'Kertas A4',
            'unit_id' => $unit->id,
            'source_code' => 'EC-TEST-01',
            'source' => 'ecount',
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'po_date' => '2026-10-28',
            'division_id' => $division->id,
            'supplier_id' => $supplier->id,
            'pic_name' => $user->name,
            'pic_user_id' => $user->id,
            'warehouse_id' => $warehouse->id,
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $user->id,
        ]);
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_id' => $item->id,
            'quantity' => 1,
            'unit_id' => $unit->id,
            'unit_price_amount' => 10000,
        ]);

        $html = Livewire::test(EditPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])->html();

        $this->assertSame(1, substr_count($html, 'data-add-row'));
        $this->assertGreaterThan(
            strpos($html, 'po-editor__table-wrap'),
            strpos($html, 'data-add-row'),
            'Halaman ubah PO juga harus menaruh tombol tambah item di bawah tabel.'
        );
    }
}
