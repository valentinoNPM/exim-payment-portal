<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Models\Division;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderConventionalEditorTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private User $maker;

    private Supplier $supplier;

    private Unit $unit;

    private Item $item;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        $this->division = Division::create([
            'code' => 'GA',
            'name' => 'General Affairs',
            'is_active' => true,
        ]);
        $this->maker = User::factory()->create([
            'division_id' => $this->division->id,
        ])->assignRole('maker');
        $this->supplier = Supplier::create([
            'code' => 'SUP-EDITOR',
            'name' => 'Vendor Editor',
            'is_active' => true,
        ]);
        $this->unit = Unit::create([
            'code' => 'PCS',
            'name' => 'Pieces',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'name' => 'Barang Editor',
            'specification' => 'Spesifikasi master',
            'unit_id' => $this->unit->id,
            'source_code' => 'EC-EDITOR-01',
            'source' => 'ecount',
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::create([
            'source_code' => 'TEST-WH',
            'name' => 'Gudang Pengujian',
            'type' => 'Gudang',
            'is_active' => true,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->maker);
    }

    public function test_create_editor_is_a_plain_html_form_without_repeater_state(): void
    {
        $html = Livewire::test(CreatePurchaseOrder::class)->html();

        $this->assertStringContainsString('data-po-editor', $html);
        $this->assertStringContainsString('data-item-dialog', $html);
        $this->assertStringContainsString('class="po-editor__add-button"', $html);
        $this->assertStringContainsString('class="po-editor__delete-button"', $html);
        $this->assertStringContainsString('data-unit-dialog', $html);
        $this->assertStringContainsString('data-header-picker-dialog', $html);
        $this->assertStringContainsString('data-open-header-picker="supplier"', $html);
        $this->assertStringContainsString('data-open-header-picker="pic"', $html);
        $this->assertStringContainsString('data-open-header-picker="warehouse"', $html);
        $this->assertStringContainsString('name="warehouse_id"', $html);
        $this->assertStringContainsString('Gudang Pengujian', $html);
        $this->assertStringContainsString('TEST-WH', $html);
        $this->assertStringNotContainsString('<select id="supplier-id"', $html);
        $this->assertStringNotContainsString('<select id="pic-user-id"', $html);
        $this->assertStringNotContainsString('<select id="warehouse-id"', $html);
        $this->assertStringNotContainsString('name="delivery_location"', $html);
        $this->assertStringNotContainsString('name="notes"', $html);
        $this->assertMatchesRegularExpression('/name="items\[[^]]+\]\[notes\]"/', $html);
        $this->assertStringContainsString('step="1"', $html);
        $this->assertMatchesRegularExpression('/name="shipping_amount"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="discount_amount"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="items\[[^]]+\]\[unit_price_amount\]"[^>]*value=""/', $html);
        $this->assertStringContainsString('novalidate', $html);
        $this->assertStringNotContainsString('fi-fo-repeater', $html);
        $this->assertStringNotContainsString('wire:model="data.items', $html);
        $this->assertLessThan(250_000, strlen($html));

        $labelsInEcountOrder = [
            'Tanggal PO',
            'Vendor',
            'PIC',
            'Nomor PO',
            'Gudang',
            'PPN (penambahan)',
            'Mata Uang',
            'Tanggal Pengiriman',
            'Biaya Kirim',
            'PPh (potongan)',
            'Status',
            'Diskon',
        ];
        $positions = array_map(fn (string $label): int|false => strpos($html, $label), $labelsInEcountOrder);
        $sortedPositions = $positions;
        sort($sortedPositions);

        $this->assertNotContains(false, $positions);
        $this->assertSame($sortedPositions, $positions, 'Urutan form PO harus mengikuti susunan ECOUNT.');
    }

    public function test_maker_can_create_po_with_master_snapshots_and_header_taxes(): void
    {
        $ppn = Tax::create([
            'code' => 'PPN-EDITOR',
            'name' => 'PPN Editor',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);

        $response = $this->post(route('purchase-orders.editor.store'), $this->payload([
            'addition_tax_id' => $ppn->id,
            'discount_amount' => 1000,
            'shipping_amount' => 2000,
            'items' => [[
                'item_id' => $this->item->id,
                'specification' => 'Spesifikasi pesanan',
                'notes' => 'Kirim terpisah per ukuran',
                'quantity' => 2,
                'unit_id' => $this->unit->id,
                'unit_price_amount' => 10000,
            ]],
        ]));

        $purchaseOrder = PurchaseOrder::query()->with(['items.taxes', 'taxes'])->firstOrFail();

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('EC-EDITOR-01', $purchaseOrder->items->first()->item_code_snapshot);
        $this->assertSame($this->warehouse->id, $purchaseOrder->warehouse_id);
        $this->assertSame('Barang Editor', $purchaseOrder->items->first()->item_name_snapshot);
        $this->assertSame('Spesifikasi pesanan', $purchaseOrder->items->first()->specification);
        $this->assertSame('Kirim terpisah per ukuran', $purchaseOrder->items->first()->notes);
        $this->assertSame('PCS', $purchaseOrder->items->first()->unit_code_snapshot);
        $this->assertSame('2200.00', $purchaseOrder->tax_addition_amount);
        $this->assertSame('23200.00', $purchaseOrder->grand_total_amount);
        $this->assertCount(1, $purchaseOrder->items->first()->taxes);
        $this->assertCount(1, $purchaseOrder->taxes);
    }

    public function test_new_master_item_requires_a_unit(): void
    {
        $response = $this->from('/admin/purchase-orders/create')
            ->post(route('purchase-orders.editor.store'), $this->payload([
                'items' => [[
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'unit_id' => null,
                    'unit_price_amount' => 10000,
                ]],
            ]));

        $response
            ->assertRedirect('/admin/purchase-orders/create')
            ->assertSessionHasErrors('items.0.unit_id');
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_decimal_quantity_can_still_be_saved_when_typed_manually(): void
    {
        $this->post(route('purchase-orders.editor.store'), $this->payload([
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1.25,
                'unit_id' => $this->unit->id,
                'unit_price_amount' => 10000,
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $line = PurchaseOrder::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame('1.2500', $line->quantity);
        $this->assertSame('12500.00', $line->subtotal_amount);
    }

    public function test_empty_optional_amounts_are_saved_as_zero(): void
    {
        $this->post(route('purchase-orders.editor.store'), $this->payload([
            'discount_amount' => '',
            'shipping_amount' => '',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $purchaseOrder = PurchaseOrder::query()->firstOrFail();

        $this->assertSame('0.00', $purchaseOrder->discount_amount);
        $this->assertSame('0.00', $purchaseOrder->shipping_amount);
    }

    public function test_empty_item_price_is_still_rejected(): void
    {
        $this->post(route('purchase-orders.editor.store'), $this->payload([
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_id' => $this->unit->id,
                'unit_price_amount' => '',
            ]],
        ]))->assertSessionHasErrors('items.0.unit_price_amount');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_item_search_is_paginated_and_returns_unit_information(): void
    {
        $response = $this->getJson(route('purchase-orders.editor.items', ['q' => 'EC-EDITOR']));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->item->id)
            ->assertJsonPath('data.0.code', 'EC-EDITOR-01')
            ->assertJsonPath('data.0.unit_name', 'Pieces')
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_edit_can_update_reorder_add_and_remove_rows(): void
    {
        $secondItem = Item::create([
            'name' => 'Barang Kedua',
            'unit_id' => $this->unit->id,
            'source_code' => 'EC-EDITOR-02',
            'source' => 'ecount',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->existingPurchaseOrder();
        $firstLine = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_id' => $this->item->id,
            'quantity' => 1,
            'unit_id' => $this->unit->id,
            'unit_price_amount' => 10000,
        ]);
        $removedLine = $purchaseOrder->items()->create([
            'line_number' => 2,
            'item_id' => $secondItem->id,
            'quantity' => 1,
            'unit_id' => $this->unit->id,
            'unit_price_amount' => 5000,
        ]);

        $secondWarehouse = Warehouse::create([
            'source_code' => 'TEST-WH-2',
            'name' => 'Gudang Pengujian Kedua',
            'is_active' => true,
        ]);
        $payload = $this->payload([
            'po_date' => $purchaseOrder->po_date->format('Y-m-d'),
            'warehouse_id' => $secondWarehouse->id,
            'items' => [
                [
                    'item_id' => $secondItem->id,
                    'quantity' => 3,
                    'unit_id' => $this->unit->id,
                    'unit_price_amount' => 5000,
                ],
                [
                    'id' => $firstLine->id,
                    'item_id' => $this->item->id,
                    'specification' => 'Ubah spesifikasi',
                    'notes' => 'Keterangan hasil edit',
                    'quantity' => 2,
                    'unit_id' => $this->unit->id,
                    'unit_price_amount' => 10000,
                ],
            ],
        ]);
        $response = $this->put(route('purchase-orders.editor.update', $purchaseOrder), $payload);

        $purchaseOrder->refresh();

        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($secondWarehouse->id, $purchaseOrder->warehouse_id);
        $this->assertDatabaseMissing('purchase_order_items', ['id' => $removedLine->id]);
        $this->assertSame([1, 2], $purchaseOrder->items()->pluck('line_number')->all());
        $this->assertSame($secondItem->id, $purchaseOrder->items()->first()->item_id);
        $this->assertSame('Ubah spesifikasi', $firstLine->fresh()->specification);
        $this->assertSame('Keterangan hasil edit', $firstLine->fresh()->notes);
        $this->assertSame('35000.00', $purchaseOrder->grand_total_amount);
    }

    public function test_item_note_edit_preserves_imported_total_and_legacy_po_note(): void
    {
        $purchaseOrder = $this->existingPurchaseOrder();
        $line = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_name' => 'Baris historis',
            'quantity' => 0,
            'unit_price_amount' => -1000,
        ]);
        $purchaseOrder->forceFill([
            'source' => 'ecount',
            'grand_total_amount' => 1234,
            'notes' => 'Catatan PO lama',
        ])->saveQuietly();

        $response = $this->put(route('purchase-orders.editor.update', $purchaseOrder), $this->payload([
            'po_date' => $purchaseOrder->po_date->format('Y-m-d'),
            'notes' => 'Input header harus diabaikan',
            'items' => [[
                'id' => $line->id,
                'item_id' => null,
                'specification' => null,
                'notes' => 'Keterangan baris diperbarui',
                'quantity' => 0,
                'unit_id' => null,
                'unit_price_amount' => -1000,
            ]],
        ]));

        $response->assertRedirect();
        $this->assertSame('1234.00', $purchaseOrder->fresh()->grand_total_amount);
        $this->assertSame('Catatan PO lama', $purchaseOrder->fresh()->notes);
        $this->assertSame('Keterangan baris diperbarui', $line->fresh()->notes);
    }

    public function test_edit_accepts_an_inactive_tax_already_attached_to_the_po(): void
    {
        $tax = Tax::create([
            'code' => 'PPN-HISTORIS',
            'name' => 'PPN Historis',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);

        $this->post(route('purchase-orders.editor.store'), $this->payload([
            'addition_tax_id' => $tax->id,
        ]))->assertSessionHasNoErrors();

        $purchaseOrder = PurchaseOrder::query()->with('items')->firstOrFail();
        $tax->update(['is_active' => false]);
        $line = $purchaseOrder->items->first();

        $this->put(route('purchase-orders.editor.update', $purchaseOrder), $this->payload([
            'po_date' => $purchaseOrder->po_date->format('Y-m-d'),
            'addition_tax_id' => $tax->id,
            'items' => [[
                'id' => $line->id,
                'item_id' => $line->item_id,
                'specification' => $line->specification,
                'notes' => 'Tetap memakai pajak historis',
                'quantity' => $line->quantity,
                'unit_id' => $line->unit_id,
                'unit_price_amount' => $line->unit_price_amount,
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Tetap memakai pajak historis', $line->fresh()->notes);
    }

    public function test_edit_keeps_an_inactive_warehouse_available_for_an_existing_po(): void
    {
        $purchaseOrder = $this->existingPurchaseOrder();
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_id' => $this->item->id,
            'quantity' => 1,
            'unit_id' => $this->unit->id,
            'unit_price_amount' => 10000,
        ]);
        $this->warehouse->update(['is_active' => false]);

        $html = Livewire::test(EditPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])->html();

        $this->assertStringContainsString('Gudang Pengujian', $html);
        $this->assertStringContainsString('TEST-WH', $html);

        $line = $purchaseOrder->items()->firstOrFail();
        $this->put(route('purchase-orders.editor.update', $purchaseOrder), $this->payload([
            'po_date' => $purchaseOrder->po_date->format('Y-m-d'),
            'items' => [[
                'id' => $line->id,
                'item_id' => $line->item_id,
                'quantity' => $line->quantity,
                'unit_id' => $line->unit_id,
                'unit_price_amount' => $line->unit_price_amount,
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($this->warehouse->id, $purchaseOrder->fresh()->warehouse_id);
    }

    public function test_fifty_row_edit_stays_below_practical_page_weight_limits(): void
    {
        $purchaseOrder = $this->existingPurchaseOrder();

        for ($line = 1; $line <= 50; $line++) {
            $purchaseOrder->items()->create([
                'line_number' => $line,
                'item_id' => $this->item->id,
                'quantity' => $line,
                'unit_id' => $this->unit->id,
                'unit_price_amount' => 10000,
            ]);
        }

        $html = Livewire::test(EditPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])->html();

        $this->assertLessThan(1_000_000, strlen($html));
        $this->assertLessThan(3_000, preg_match_all('/<[^!][^>]*>/', $html));
        $this->assertSame(0, preg_match_all('/wire:model[^=]*=/i', $html));
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'po_date' => '2026-10-07',
            'supplier_id' => $this->supplier->id,
            'pic_user_id' => $this->maker->id,
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_NEW,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'delivery_date' => '2026-10-07',
            'warehouse_id' => $this->warehouse->id,
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'unit_id' => $this->unit->id,
                'unit_price_amount' => 10000,
            ]],
        ], $overrides);
    }

    private function existingPurchaseOrder(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_date' => '2026-10-07',
            'division_id' => $this->division->id,
            'supplier_id' => $this->supplier->id,
            'pic_name' => $this->maker->name,
            'pic_user_id' => $this->maker->id,
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->maker->id,
        ]);
    }
}
