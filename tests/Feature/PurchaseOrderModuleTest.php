<?php

namespace Tests\Feature;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Division;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItemTax;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseOrders\PurchaseOrderNumberGenerator;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class PurchaseOrderModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Division $gaDivision;

    protected User $gaMaker;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        Role::create(['name' => 'checker']);
        $this->gaDivision = Division::create([
            'code' => 'GA',
            'name' => 'General Affairs',
            'is_active' => true,
        ]);
        $this->gaMaker = User::factory()->create([
            'division_id' => $this->gaDivision->id,
        ])->assignRole('maker');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_only_ga_makers_can_access_purchase_orders(): void
    {
        $this->actingAs($this->gaMaker)
            ->get(PurchaseOrderResource::getUrl('index'))
            ->assertOk();

        $hrDivision = Division::create([
            'code' => 'HR',
            'name' => 'Human Resources',
            'is_active' => true,
        ]);
        $hrMaker = User::factory()->create([
            'division_id' => $hrDivision->id,
        ])->assignRole('maker');

        $this->actingAs($hrMaker)
            ->get(PurchaseOrderResource::getUrl('index'))
            ->assertForbidden();

        $checker = User::factory()->create([
            'division_id' => $this->gaDivision->id,
        ])->assignRole('checker');

        $this->actingAs($checker)
            ->get(PurchaseOrderResource::getUrl('index'))
            ->assertForbidden();

        $this->assertFalse(ItemResource::canAccess());

        $this->actingAs($this->gaMaker);
        $this->assertTrue(ItemResource::canAccess());
    }

    public function test_ecount_key_column_is_hidden_by_default(): void
    {
        $this->actingAs($this->gaMaker);

        $column = Livewire::test(ListPurchaseOrders::class)
            ->instance()
            ->getTable()
            ->getColumn('source_code');

        $this->assertNotNull($column);
        $this->assertTrue($column->isToggleable());
        $this->assertTrue($column->isToggledHiddenByDefault());
    }

    public function test_number_generator_continues_after_highest_imported_daily_sequence(): void
    {
        $supplier = Supplier::create([
            'code' => 'SUP-IMPORT-SEQUENCE',
            'name' => 'Vendor Import Sequence',
            'is_active' => true,
        ]);

        PurchaseOrder::create([
            'po_number' => 'PO/HIJ/05102026-000012',
            'company_code' => 'HIJ',
            'sequence_number' => 12,
            'po_date' => '2026-10-05',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC ECOUNT',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_COMPLETED,
            'created_by' => $this->gaMaker->id,
            'source' => 'ecount',
            'source_code' => '05/10/2026 -12',
        ]);
        DB::table('purchase_order_sequences')->insert([
            'company_code' => 'HIJ',
            'scope_key' => '20261005',
            'last_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $generator = app(PurchaseOrderNumberGenerator::class);
        $this->assertSame(13, $generator->preview(Carbon::parse('2026-10-05'))['sequence_number']);

        $created = PurchaseOrder::create([
            'po_date' => '2026-10-05',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC GA',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);

        $this->assertSame(13, $created->sequence_number);
        $this->assertSame('PO/HIJ/05102026-000013', $created->po_number);
    }

    public function test_create_page_shows_draft_number_without_editable_field(): void
    {
        $this->actingAs($this->gaMaker);

        $html = Livewire::test(CreatePurchaseOrder::class)->html();
        $draft = app(PurchaseOrderNumberGenerator::class)->preview(today());

        $this->assertStringContainsString($draft['po_number'], $html, 'Halaman PO Baru tidak menampilkan nomor draf.');
        $this->assertStringContainsString('belum paten', $html);
        $this->assertStringNotContainsString('wire:model="data.po_number"', $html, 'Nomor PO tidak boleh bisa diisi manual.');
        // pratinjau tidak boleh memakai urutan
        $this->assertSame(0, (int) DB::table('purchase_order_sequences')->sum('last_number'));

        // nomor draf mengikuti tanggal yang dipilih
        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm(['po_date' => '2026-12-25'])
            ->assertSee('PO/HIJ/25122026-');
    }

    public function test_preview_number_is_not_reserved_and_equals_the_saved_po_number(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-DRAF',
            'name' => 'Vendor Draf',
            'is_active' => true,
        ]);

        $generator = app(PurchaseOrderNumberGenerator::class);
        $preview = $generator->preview(Carbon::parse('2026-10-03'));

        // pratinjau berulang tetap sama dan tidak menggerus urutan
        $this->assertSame($preview['po_number'], $generator->preview(Carbon::parse('2026-10-03'))['po_number']);
        $this->assertSame(0, (int) DB::table('purchase_order_sequences')->sum('last_number'));

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'items' => [[
                    'item_code' => 'DRAF-001',
                    'item_name' => 'Barang uji',
                    'quantity' => 1,
                    'unit_price_amount' => 1000,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = PurchaseOrder::query()->firstOrFail();

        // nomor paten saat simpan = nomor yang tadi tampil sebagai draf, urutan baru terpakai 1
        $this->assertSame('PO/HIJ/03102026-000001', $saved->po_number);
        $this->assertSame($preview['po_number'], $saved->po_number);
        $this->assertSame(1, (int) DB::table('purchase_order_sequences')->sum('last_number'));
    }

    public function test_ga_maker_can_create_po_with_items_and_automatic_number(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-PO-01',
            'name' => 'PT Vendor GA',
            'address' => 'Boyolali',
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'code' => 'BOX',
            'name' => 'Box',
            'is_active' => true,
        ]);

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'pic_name' => 'Staf GA',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'delivery_date' => '2026-10-10',
                'delivery_location' => 'Gudang GA',
                'items' => [[
                    'item_code' => 'PAN-001',
                    'item_name' => 'Air mineral',
                    'specification' => '600 ml',
                    'quantity' => 10,
                    'unit_id' => $unit->id,
                    'unit_price_amount' => 25000,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchaseOrder = PurchaseOrder::query()->with('items')->firstOrFail();

        $this->assertSame('PO/HIJ/03102026-000001', $purchaseOrder->po_number);
        $this->assertSame($this->gaDivision->id, $purchaseOrder->division_id);
        $this->assertSame($this->gaMaker->id, $purchaseOrder->created_by);
        $this->assertSame('250000.00', $purchaseOrder->subtotal_amount);
        $this->assertSame('250000.00', $purchaseOrder->grand_total_amount);
        $this->assertSame('250000.00', $purchaseOrder->items->first()->subtotal_amount);
        $this->assertDatabaseHas('purchase_order_audits', [
            'purchase_order_id' => $purchaseOrder->id,
            'event' => 'created',
        ]);

        $second = PurchaseOrder::create([
            'po_date' => '2026-10-03',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'Staf GA',
            'currency' => PurchaseOrder::CURRENCY_USD,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);

        $this->assertSame('PO/HIJ/03102026-000002', $second->po_number);

        $second->update(['status' => PurchaseOrder::STATUS_SENT_TO_VENDOR]);
        $audit = $second->audits()->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame(PurchaseOrder::STATUS_NEW, $audit->old_values['status']);
        $this->assertSame(PurchaseOrder::STATUS_SENT_TO_VENDOR, $audit->new_values['status']);
    }

    public function test_po_list_and_pdf_preview_show_purchase_order_data(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-PDF',
            'name' => 'PT Vendor Cetak',
            'address' => 'Jl. Vendor 1',
            'is_active' => true,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'po_date' => '2026-10-03',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC GA',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'delivery_location' => 'Gudang Hansoll',
            'title' => 'Judul internal tidak boleh tampil',
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_code' => 'ATK-01',
            'item_name' => 'Kertas A4',
            'specification' => '80 gsm',
            'quantity' => 5,
            'unit_price_amount' => 60000,
        ]);

        Livewire::test(ListPurchaseOrders::class)
            ->assertCanSeeTableRecords([$purchaseOrder]);

        $response = $this->get(route('purchase-orders.pdf.preview', $purchaseOrder));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('content-disposition'));

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder->fresh()->load(['division', 'supplier', 'creator', 'items.unit', 'items.item']),
        ])->render();
        // Bentuk cetakan mengikuti PDF ECOUNT (PROSES.md butir 18).
        $this->assertStringContainsString('Purchase Order', $html);
        $this->assertStringContainsString('Purchase Order No.', $html);
        $this->assertStringContainsString($purchaseOrder->po_number, $html);
        $this->assertStringContainsString('Vendor', $html);
        $this->assertStringContainsString('Ship To', $html);
        $this->assertStringContainsString('PT HANSOLL INDO JAVA', $html);
        $this->assertStringContainsString('Kode Barang', $html);
        $this->assertStringContainsString('Nama Barang [Spec.]', $html);
        $this->assertStringContainsString('Jumlah Sebelum Pajak', $html);
        $this->assertStringContainsString('Jumlah Keseluruhan', $html);
        $this->assertStringContainsString('SUB TOTAL', $html);
        $this->assertStringContainsString('PT Vendor Cetak', $html);
        $this->assertStringContainsString('Kertas A4', $html);
        $this->assertStringNotContainsString('Judul internal tidak boleh tampil', $html);
        // kolom approval berupa dua kotak bergaris seperti cetakan ECOUNT
        foreach (['PIC', 'Area Manager', 'Factory Manager', 'Direktur', 'GA', 'Manager'] as $peran) {
            $this->assertStringContainsString($peran, $html, "Kolom approval tidak memuat: {$peran}");
        }
        // Angka IDR mengikuti cetakan ECOUNT: pemisah ribuan tanpa desimal dan tanpa awalan mata uang.
        $this->assertStringContainsString('60.000', $html);
        $this->assertStringContainsString('300.000', $html);
        $this->assertStringNotContainsString('Rp ', $html);
        // dokumen rupiah tidak memakai kolom mata uang asing
        $this->assertStringNotContainsString('Tipe Mata Uang Asing', $html);
    }

    public function test_po_tax_uses_snapshot_and_is_rendered_in_pdf(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-TAX',
            'name' => 'PT Vendor Pajak',
            'is_active' => true,
        ]);
        $ppn = Tax::create([
            'code' => 'PPN-11-PO',
            'name' => 'PPN 11% PO',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'po_date' => '2026-10-03',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC Pajak',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);
        $item = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_name' => 'Barang kena pajak',
            'quantity' => 10,
            'unit_price_amount' => 25000,
        ]);

        $item->selectedTaxes()->attach($ppn->id);

        $itemTax = PurchaseOrderItemTax::query()->firstOrFail();
        $this->assertSame('11.0000', $itemTax->rate_snapshot);
        $this->assertSame('250000.00', $itemTax->taxable_amount);
        $this->assertSame('27500.00', $itemTax->tax_amount);
        $this->assertSame('27500.00', $purchaseOrder->fresh()->tax_amount);
        $this->assertSame('277500.00', $purchaseOrder->fresh()->grand_total_amount);
        $this->assertDatabaseHas('purchase_order_taxes', [
            'purchase_order_id' => $purchaseOrder->id,
            'tax_id' => $ppn->id,
            'rate_snapshot' => 11,
            'tax_amount' => 27500,
        ]);

        $ppn->update(['rate' => 12]);
        $item->fresh()->recalculateTaxes();

        $this->assertSame('11.0000', $itemTax->fresh()->rate_snapshot);
        $this->assertSame('27500.00', $itemTax->fresh()->tax_amount);
        $this->assertSame('277500.00', $purchaseOrder->fresh()->grand_total_amount);

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder->fresh()->load(['division', 'supplier', 'creator', 'items.unit', 'items.item']),
        ])->render();
        // Pajak dan total IDR mengikuti cetakan ECOUNT (tanpa desimal dan tanpa awalan Rp).
        $this->assertStringContainsString('27.500', $html);
        $this->assertStringContainsString('277.500', $html);
        $this->assertStringNotContainsString('Rp 27.500', $html);
    }

    public function test_header_tax_selection_automatically_applies_tax_to_all_items(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-BULK-TAX',
            'name' => 'PT Vendor Pajak Massal',
            'is_active' => true,
        ]);
        $ppn = Tax::create([
            'code' => 'PPN-BULK-11',
            'name' => 'PPN Massal 11%',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);

        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'pic_name' => 'PIC Pajak Massal',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'items' => [
                    [
                        'item_name' => 'Item satu',
                        'quantity' => 1,
                        'unit_price_amount' => 100000,
                    ],
                    [
                        'item_name' => 'Item dua',
                        'quantity' => 3,
                        'unit_price_amount' => 50000,
                    ],
                ],
            ])
            ->set('data.addition_tax_id', $ppn->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $purchaseOrder = PurchaseOrder::query()->with('items.taxes')->firstOrFail();

        $this->assertCount(2, $purchaseOrder->items);
        $this->assertSame(2, PurchaseOrderItemTax::query()->where('tax_id', $ppn->id)->count());
        $this->assertSame('27500.00', $purchaseOrder->tax_amount);
        $this->assertSame('277500.00', $purchaseOrder->grand_total_amount);
    }

    public function test_selecting_no_header_tax_clears_all_item_tax_and_restores_subtotal(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-NO-TAX',
            'name' => 'PT Vendor Tanpa Pajak',
            'is_active' => true,
        ]);
        $ppn = Tax::create([
            'code' => 'PPN-CLEAR-11',
            'name' => 'PPN Hapus 11%',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);

        $component = Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'pic_name' => 'PIC Tanpa Pajak',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'addition_tax_id' => $ppn->id,
                'items' => [[
                    'item_name' => 'Item bersih pajak',
                    'quantity' => 2,
                    'unit_price_amount' => 75000,
                ]],
            ]);

        $component
            ->set('data.addition_tax_id', null)
            ->call('create')
            ->assertHasNoFormErrors();

        $purchaseOrder = PurchaseOrder::query()->firstOrFail();

        $this->assertSame(0, PurchaseOrderItemTax::query()->count());
        $this->assertSame('150000.00', $purchaseOrder->subtotal_amount);
        $this->assertSame('0.00', $purchaseOrder->tax_amount);
        $this->assertSame('150000.00', $purchaseOrder->grand_total_amount);
    }

    public function test_po_form_exposes_ppn_pph_discount_and_shipping_and_totals_them(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-MONEY-UI',
            'name' => 'PT Vendor Uang',
            'is_active' => true,
        ]);
        $ppn = Tax::create([
            'code' => 'PPN-UI-11',
            'name' => 'PPN UI 11%',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);
        $pph = Tax::create([
            'code' => 'PPH-UI-2',
            'name' => 'PPh UI 2%',
            'rate' => 2,
            'calculation_type' => 'deduction',
            'is_active' => true,
        ]);

        Livewire::test(CreatePurchaseOrder::class)
            ->assertSchemaComponentExists('addition_tax_id')
            ->assertSchemaComponentExists('deduction_tax_id')
            ->assertSchemaComponentExists('discount_amount')
            ->assertSchemaComponentExists('shipping_amount')
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'pic_name' => 'PIC Uang',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'discount_amount' => 10000,
                'shipping_amount' => 20000,
                'items' => [[
                    'item_name' => 'Item uang',
                    'quantity' => 1,
                    'unit_price_amount' => 100000,
                ]],
            ])
            ->set('data.addition_tax_id', $ppn->id)
            ->set('data.deduction_tax_id', $pph->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $purchaseOrder = PurchaseOrder::query()->firstOrFail();

        // 100000 + 11000 (PPN) - 2000 (PPh) - 10000 (diskon) + 20000 (kirim) = 119000
        $this->assertSame('11000.00', $purchaseOrder->tax_addition_amount);
        $this->assertSame('2000.00', $purchaseOrder->tax_deduction_amount);
        $this->assertSame('9000.00', $purchaseOrder->tax_amount);
        $this->assertSame('10000.00', $purchaseOrder->discount_amount);
        $this->assertSame('20000.00', $purchaseOrder->shipping_amount);
        $this->assertSame('119000.00', $purchaseOrder->grand_total_amount);
        $this->assertSame(1, PurchaseOrderItemTax::query()->where('tax_id', $ppn->id)->count());
        $this->assertSame(1, PurchaseOrderItemTax::query()->where('tax_id', $pph->id)->count());
    }

    public function test_po_total_adds_addition_tax_and_subtracts_deduction_tax(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-TAX-MIXED',
            'name' => 'PT Vendor Pajak Campuran',
            'is_active' => true,
        ]);
        $ppn = Tax::create([
            'code' => 'PPN-11-MIXED',
            'name' => 'PPN 11% Campuran',
            'rate' => 11,
            'calculation_type' => 'addition',
            'is_active' => true,
        ]);
        $pph = Tax::create([
            'code' => 'PPH-2-MIXED',
            'name' => 'PPh 2% Campuran',
            'rate' => 2,
            'calculation_type' => 'deduction',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO-TAX-MIXED');
        $item = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_name' => 'Barang pajak campuran',
            'quantity' => 10,
            'unit_price_amount' => 25000,
        ]);

        $item->selectedTaxes()->attach([$ppn->id, $pph->id]);

        $purchaseOrder->update(['discount_amount' => 5000, 'shipping_amount' => 10000]);
        $purchaseOrder->recalculateTotals();

        $this->assertSame('250000.00', $item->fresh()->subtotal_amount);
        $this->assertSame('22500.00', $item->fresh()->tax_amount);
        $this->assertSame('272500.00', $item->fresh()->total_amount);
        $this->assertSame('27500.00', $purchaseOrder->fresh()->tax_addition_amount);
        $this->assertSame('5000.00', $purchaseOrder->fresh()->tax_deduction_amount);
        $this->assertSame('22500.00', $purchaseOrder->fresh()->tax_amount);
        // 250000 + 27500 (PPN) - 5000 (PPh) - 5000 (diskon) + 10000 (kirim) = 277500
        $this->assertSame('277500.00', $purchaseOrder->fresh()->grand_total_amount);
    }

    public function test_linked_invoices_are_shown_and_outstanding_scope_excludes_the_po(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-INV',
            'name' => 'PT Vendor Invoice',
            'is_active' => true,
        ]);
        $linkedPo = $this->createPurchaseOrder($supplier, 'PO-LINKED');
        $outstandingPo = $this->createPurchaseOrder($supplier, 'PO-OUTSTANDING');
        $paymentSlip = ErpPaymentSlip::create($this->gaMaker);

        $paymentSlip->invoices()->update(['purchase_order_id' => $linkedPo->id]);

        $this->assertCount(2, $linkedPo->invoices()->get());
        $this->assertFalse(PurchaseOrder::query()->outstanding()->whereKey($linkedPo)->exists());
        $this->assertTrue(PurchaseOrder::query()->outstanding()->whereKey($outstandingPo)->exists());

        $invoiceHtml = view('filament.purchase-orders.linked-invoices', [
            'invoices' => $linkedPo->invoices()->with('paymentSlip')->orderBy('invoice_number')->get(),
        ])->render();
        $this->assertStringContainsString('000045', $invoiceHtml);
        $this->assertStringContainsString('000046', $invoiceHtml);
        $this->assertStringNotContainsString('Belum ada invoice', $invoiceHtml);

        $emptyHtml = view('filament.purchase-orders.linked-invoices', [
            'invoices' => $outstandingPo->invoices()->get(),
        ])->render();
        $this->assertStringContainsString('Belum ada invoice', $emptyHtml);

        Livewire::test(ViewPurchaseOrder::class, ['record' => $linkedPo->getRouteKey()])
            ->assertSee('000045')
            ->assertSee('000046');

        $linkedPo->delete();
        $this->assertSame(2, Invoice::query()->whereNull('purchase_order_id')->count());
    }

    public function test_item_master_generates_unique_codes_from_config(): void
    {
        config()->set('purchase-orders.item_code_prefix', 'POITEM');

        $first = Item::create(['name' => 'Item pertama']);
        $second = Item::create(['name' => 'Item kedua']);

        $this->assertSame('POITEM-000001', $first->code);
        $this->assertSame('POITEM-000002', $second->code);
        $this->assertNotSame($first->code, $second->code);
    }

    public function test_po_item_grid_combines_code_and_name_in_one_master_selector(): void
    {
        $this->actingAs($this->gaMaker);
        Item::create(['name' => 'Barang Pilihan']);

        $component = Livewire::test(CreatePurchaseOrder::class);
        $html = $component->html();
        $itemKey = array_key_first($component->get('data.items'));

        $this->assertStringContainsString('Nama Barang', $html);
        $this->assertStringContainsString('width: 360px', $html);
        $this->assertStringNotContainsString('Kode Barang', $html);
        $this->assertStringContainsString('Pilih barang', $html);
        $this->assertStringContainsString('Pilih satuan', $html);
        $this->assertStringNotContainsString('Cari barang', $html);
        $this->assertStringNotContainsString('Buat opsi', $html);

        $component->assertFormComponentActionExists("items.{$itemKey}.item_id", 'select');
    }

    public function test_po_list_can_be_filtered_by_period(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create(['code' => 'SUP-PERIODE', 'name' => 'Vendor Periode', 'is_active' => true]);

        $lama = $this->createPurchaseOrder($supplier, 'PO lama');
        $lama->forceFill(['po_date' => '2025-01-15'])->save();

        $baru = $this->createPurchaseOrder($supplier, 'PO baru');
        $baru->forceFill(['po_date' => '2026-10-03'])->save();

        Livewire::test(ListPurchaseOrders::class)
            ->filterTable('periode', ['dari' => '2026-01-01', 'sampai' => '2026-12-31'])
            ->assertCanSeeTableRecords([$baru])
            ->assertCanNotSeeTableRecords([$lama]);
    }

    public function test_po_screens_use_hansoll_ecount_vocabulary(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create(['code' => 'SUP-LABEL', 'name' => 'Vendor Label', 'is_active' => true]);
        $this->createPurchaseOrder($supplier, 'PO label');

        $listHtml = Livewire::test(ListPurchaseOrders::class)->html();

        foreach (['Daftar Pesanan Pembelian', 'No. PO', 'Vendor', 'Divisi', 'Barang', 'Tanggal Selesai', 'Total', 'Status', 'Cetak'] as $label) {
            $this->assertStringContainsString($label, $listHtml, "Label daftar PO tidak sesuai kosakata klien: {$label}");
        }

        $createHtml = Livewire::test(CreatePurchaseOrder::class)->html();

        foreach (['Data PO', 'Item PO', 'Nama Barang', 'Kuantitas', 'Satuan', 'Harga', 'Jumlah Sebelum Pajak', 'Pajak', 'Mata Uang', 'Tanggal Selesai', 'Keterangan'] as $label) {
            $this->assertStringContainsString($label, $createHtml, "Label form PO tidak sesuai kosakata klien: {$label}");
        }

        $this->assertStringNotContainsString('Kode Barang', $createHtml);
        $this->assertStringNotContainsString('Judul', $createHtml);
        $this->assertStringNotContainsString('Aturan pajak HANSOLL', $createHtml);
    }

    public function test_item_form_hides_internal_source_fields_and_displays_saved_system_code_as_text(): void
    {
        $this->actingAs($this->gaMaker);

        Livewire::test(CreateItem::class)
            ->assertDontSee('Kode Asli ECOUNT')
            ->assertDontSee('Sumber');

        $item = Item::create(['name' => 'Item manual']);

        $html = Livewire::test(EditItem::class, ['record' => $item->getRouteKey()])->html();

        $this->assertStringContainsString('Kode Sistem', $html);
        $this->assertStringContainsString($item->code, $html);
        $this->assertStringNotContainsString('Kode Asli ECOUNT', $html);
        $this->assertStringNotContainsString('Sumber', $html);
        $this->assertSame('manual', $item->source);
    }

    public function test_po_view_renders_plain_text_without_form_controls(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create(['code' => 'SUP-VIEW', 'name' => 'Vendor View', 'is_active' => true]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO teks biasa');
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_code' => 'VIEW-01',
            'item_name' => 'Item View',
            'quantity' => 2,
            'unit_price_amount' => 15000,
        ]);

        $html = Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])->html();
        $visibleHtml = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $html) ?? $html;

        $this->assertStringContainsString('VIEW-01', $visibleHtml);
        $this->assertStringContainsString('Item View', $visibleHtml);
        $this->assertStringNotContainsString('Judul / Keperluan', $visibleHtml);
        $this->assertStringNotContainsString('PO teks biasa', $visibleHtml);
        $this->assertSame(0, preg_match_all('/<input\b/i', $html));
        $this->assertSame(0, preg_match_all('/<select\b/i', $html));
        $this->assertSame(0, preg_match_all('/wire:model/i', $html));
    }

    public function test_selecting_item_master_fills_line_defaults_and_persists_snapshots(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-ITEM-MASTER',
            'name' => 'PT Vendor Master Item',
            'is_active' => true,
        ]);
        $unit = Unit::create([
            'code' => 'ROLL',
            'name' => 'Roll',
            'is_active' => true,
        ]);
        $masterItem = Item::create([
            'name' => 'Kain Twill',
            'specification' => 'Lebar 150 cm',
            'unit_id' => $unit->id,
            'source_code' => 'ECOUNT-001',
            'source' => 'ecount',
        ]);

        $component = Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-03',
                'supplier_id' => $supplier->id,
                'pic_name' => 'PIC Master Item',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'items' => [[
                    'item_id' => $masterItem->id,
                    'quantity' => 2,
                    'unit_price_amount' => 100000,
                ]],
            ]);

        $itemsState = $component->get('data.items');
        $itemKey = array_key_first($itemsState);

        $component
            ->set("data.items.{$itemKey}.item_id", $masterItem->id)
            ->assertSet("data.items.{$itemKey}.item_name", 'Kain Twill')
            ->assertSet("data.items.{$itemKey}.specification", 'Lebar 150 cm')
            ->assertSet("data.items.{$itemKey}.unit_id", $unit->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $line = PurchaseOrder::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame($masterItem->id, $line->item_id);
        $this->assertSame('ECOUNT-001', $line->item_code_snapshot);
        $this->assertSame('Kain Twill', $line->item_name_snapshot);
        $this->assertSame('Lebar 150 cm', $line->specification_snapshot);
        $this->assertSame($unit->id, $line->unit_id);
        $this->assertSame('ROLL', $line->unit_code_snapshot);

        $formHtml = Livewire::test(EditPurchaseOrder::class, ['record' => $line->purchase_order_id])->html();

        $this->assertStringContainsString('Roll', $formHtml);
        $this->assertStringNotContainsString('ROLL - Roll', $formHtml);
    }

    public function test_new_po_line_requires_unit_and_sets_missing_item_default_without_overwriting_existing_default(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-UNIT-DEFAULT',
            'name' => 'PT Vendor Unit Default',
            'is_active' => true,
        ]);
        $pieces = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $boxes = Unit::create(['code' => 'BOX', 'name' => 'Box', 'is_active' => true]);
        $masterWithoutUnit = Item::create([
            'name' => 'Barang Tanpa Satuan',
            'source_code' => 'EC-NO-UNIT',
            'source' => 'ecount',
        ]);

        $component = Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'po_date' => '2026-10-07',
                'supplier_id' => $supplier->id,
                'pic_name' => 'PIC Unit',
                'currency' => PurchaseOrder::CURRENCY_IDR,
                'status' => PurchaseOrder::STATUS_NEW,
                'items' => [[
                    'item_id' => $masterWithoutUnit->id,
                    'quantity' => 1,
                    'unit_price_amount' => 10000,
                ]],
            ]);

        $itemKey = array_key_first($component->get('data.items'));

        $component
            ->call('create')
            ->assertHasFormErrors(["items.{$itemKey}.unit_id" => 'required'])
            ->set("data.items.{$itemKey}.unit_id", $pieces->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $line = PurchaseOrder::query()->firstOrFail()->items()->firstOrFail();

        $this->assertSame($pieces->id, $line->unit_id);
        $this->assertSame('PCS', $line->unit_code_snapshot);
        $this->assertSame($pieces->id, $masterWithoutUnit->fresh()->unit_id);

        $line->update(['unit_id' => $boxes->id]);

        $this->assertSame($boxes->id, $line->fresh()->unit_id);
        $this->assertSame('BOX', $line->unit_code_snapshot);
        $this->assertSame($pieces->id, $masterWithoutUnit->fresh()->unit_id);
    }

    public function test_item_master_changes_do_not_change_existing_po_pdf_snapshot(): void
    {
        $supplier = Supplier::create([
            'code' => 'SUP-ITEM-SNAPSHOT',
            'name' => 'PT Vendor Snapshot Item',
            'is_active' => true,
        ]);
        $masterItem = Item::create([
            'name' => 'Nama Historis',
            'specification' => 'Spesifikasi Historis',
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO snapshot item');
        $line = $purchaseOrder->items()->create([
            'item_id' => $masterItem->id,
            'line_number' => 1,
            'item_name' => 'akan diisi snapshot',
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $masterItem->update([
            'name' => 'Nama Baru',
            'specification' => 'Spesifikasi Baru',
        ]);
        $line->update(['quantity' => 2]);

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder->fresh()->load(['division', 'supplier', 'creator', 'items.unit']),
        ])->render();

        $this->assertStringContainsString('Nama Historis', $html);
        $this->assertStringContainsString('Spesifikasi Historis', $html);
        $this->assertStringNotContainsString('Nama Baru', $html);
        $this->assertStringNotContainsString('Spesifikasi Baru', $html);
    }

    public function test_supplier_item_code_and_unit_changes_do_not_change_existing_po_pdf(): void
    {
        $this->actingAs($this->gaMaker);

        $supplier = Supplier::create([
            'code' => 'SUP-HISTORY',
            'name' => 'Vendor Historis',
            'address' => 'Alamat Historis',
            'is_active' => true,
        ]);
        $unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces', 'is_active' => true]);
        $item = Item::create([
            'name' => 'Barang Historis',
            'source_code' => 'EC-OLD',
            'unit_id' => $unit->id,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'Snapshot cetak');
        $purchaseOrder->items()->create([
            'item_id' => $item->id,
            'line_number' => 1,
            'item_name' => 'Barang Historis',
            'quantity' => 1,
            'unit_price_amount' => 1000,
        ]);

        $supplier->update(['name' => 'Vendor Baru', 'address' => 'Alamat Baru']);
        $item->update(['source_code' => 'EC-NEW']);
        $unit->update(['code' => 'BOX']);

        $detailHtml = Livewire::test(ViewPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])->html();

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder->fresh()->load(['supplier', 'items.item', 'items.unit', 'taxes']),
        ])->render();

        $this->assertStringContainsString('Vendor Historis', $html);
        $this->assertStringContainsString('Alamat Historis', $html);
        $this->assertStringContainsString('EC-OLD', $html);
        $this->assertStringContainsString('PCS', $html);
        $this->assertStringContainsString('PCS', $detailHtml);
        $this->assertStringNotContainsString('Vendor Baru', $html);
        $this->assertStringNotContainsString('EC-NEW', $html);
        $this->assertStringNotContainsString('BOX', $detailHtml);
    }

    public function test_editing_imported_po_header_accepts_legacy_values_and_preserves_source_total(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-LEGACY-VALUES',
            'name' => 'Vendor Legacy Values',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO historis');
        $purchaseOrder->forceFill([
            'source' => 'ecount',
            'source_code' => '03/10/2026 -99',
        ])->saveQuietly();
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_name' => 'Diskon Pembelian',
            'quantity' => 0,
            'unit_price_amount' => -1000,
        ]);
        $purchaseOrder->forceFill(['grand_total_amount' => 1234])->saveQuietly();

        Livewire::test(EditPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])
            ->fillForm(['notes' => 'Catatan diperbarui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1234.00', $purchaseOrder->fresh()->grand_total_amount);
        $this->assertSame('Catatan diperbarui', $purchaseOrder->fresh()->notes);
    }

    public function test_legacy_text_only_po_item_can_still_be_saved(): void
    {
        $supplier = Supplier::create([
            'code' => 'SUP-LEGACY-ITEM',
            'name' => 'PT Vendor Item Lama',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO item lama');

        $line = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_code' => 'OLD-001',
            'item_name' => 'Item teks lama',
            'specification' => 'Tanpa master',
            'quantity' => 3,
            'unit_price_amount' => 12000,
        ]);

        $this->assertNull($line->item_id);
        $this->assertSame('OLD-001', $line->item_code);
        $this->assertSame('Item teks lama', $line->item_name);
        $this->assertSame('36000.00', $line->subtotal_amount);
    }

    public function test_ga_maker_can_edit_po_without_losing_document_identity(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-EDIT',
            'name' => 'PT Vendor Ubah',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'Judul awal');
        $line = $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_code' => 'EDIT-01',
            'item_name' => 'Item diubah',
            'quantity' => 2,
            'unit_price_amount' => 50000,
        ]);
        // Menirukan dokumen hasil impor ECOUNT: identitas ada di kunci ECOUNT.
        $purchaseOrder->forceFill(['source_code' => '03/10/2026 -1'])->saveQuietly();
        $nomorAwal = $purchaseOrder->fresh()->po_number;

        $component = Livewire::test(EditPurchaseOrder::class, ['record' => $purchaseOrder->getRouteKey()])
            ->assertOk()
            ->assertFormSet([
                'source_code' => '03/10/2026 -1',
            ]);

        $editHtml = $component->html();
        $visibleEditHtml = preg_replace('/\s+wire:snapshot="[^"]*"/', '', $editHtml) ?? $editHtml;

        $this->assertStringNotContainsString('Judul', $visibleEditHtml);

        $barisKunci = array_key_first($component->get('data.items'));

        $component
            ->fillForm([
                'delivery_location' => 'Gudang baru',
            ])
            ->set("data.items.{$barisKunci}.quantity", 4)
            ->call('save')
            ->assertHasNoFormErrors();

        $purchaseOrder->refresh();

        $this->assertSame('Judul awal', $purchaseOrder->title);
        $this->assertSame('Gudang baru', $purchaseOrder->delivery_location);

        // PO dibiarkan seperti dokumen impor (PIC sistem kosong): menyimpan harus tetap bisa,
        // PIC sistem terisi otomatis, dan nama PIC asli tidak boleh tertimpa.
        $this->assertSame($this->gaMaker->id, $purchaseOrder->pic_user_id);
        $this->assertSame('PIC GA', $purchaseOrder->pic_name);

        // Identitas dokumen asli tidak boleh ikut berubah saat mengubah PO.
        $this->assertSame($nomorAwal, $purchaseOrder->po_number);
        $this->assertSame('03/10/2026 -1', $purchaseOrder->source_code);
        $this->assertSame($this->gaDivision->id, $purchaseOrder->division_id);
        $this->assertSame($this->gaMaker->id, $purchaseOrder->created_by);

        // Baris yang sama diperbarui, bukan dibuat ulang.
        $baris = $purchaseOrder->items()->firstOrFail();
        $this->assertSame($line->id, $baris->id);
        $this->assertSame('4.0000', $baris->quantity);
        $this->assertSame('200000.00', $baris->subtotal_amount);

        // Jejak perubahan tercatat.
        $this->assertDatabaseHas('purchase_order_audits', [
            'purchase_order_id' => $purchaseOrder->id,
            'event' => 'updated',
        ]);
    }

    public function test_usd_po_pdf_uses_foreign_currency_columns(): void
    {
        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-USD-PDF',
            'name' => 'PT Vendor Dolar',
            'address' => 'Jakarta',
            'is_active' => true,
        ]);
        $purchaseOrder = PurchaseOrder::create([
            'po_date' => '2026-10-03',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC Dolar',
            'currency' => PurchaseOrder::CURRENCY_USD,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);
        $purchaseOrder->items()->create([
            'line_number' => 1,
            'item_code' => 'MESIN-01',
            'item_name' => 'Mesin jahit',
            'quantity' => 10,
            'unit_price_amount' => 70,
        ]);

        $response = $this->get(route('purchase-orders.pdf.preview', $purchaseOrder));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $purchaseOrder->fresh()->load(['division', 'supplier', 'creator', 'items.unit', 'items.item']),
        ])->render();

        // Dokumen mata uang asing memakai kolom Jumlah Mata Uang Asing + Tipe Mata Uang Asing.
        $this->assertStringContainsString('Jumlah Mata Uang Asing', $html);
        $this->assertStringContainsString('Tipe Mata Uang Asing', $html);
        $this->assertStringContainsString('>USD<', $html);
        $this->assertStringNotContainsString('Jumlah Sebelum Pajak', $html);
        $this->assertStringContainsString('700.00', $html);
    }

    public function test_status_dalam_proses_memakai_istilah_ecount(): void
    {
        // Istilah status mengikuti yang dibaca klien di ECOUNT (lihat komentar STATUS_LABELS).
        $this->assertSame('in_progress', PurchaseOrder::STATUS_IN_PROGRESS);
        $this->assertSame('Dalam Proses', PurchaseOrder::STATUS_LABELS[PurchaseOrder::STATUS_IN_PROGRESS]);
        $this->assertSame('Selesai', PurchaseOrder::STATUS_LABELS[PurchaseOrder::STATUS_COMPLETED]);
        $this->assertSame('Dikirim ke Vendor', PurchaseOrder::STATUS_LABELS[PurchaseOrder::STATUS_SENT_TO_VENDOR]);

        $this->actingAs($this->gaMaker);
        $supplier = Supplier::create([
            'code' => 'SUP-STATUS',
            'name' => 'PT Vendor Status',
            'is_active' => true,
        ]);
        $purchaseOrder = $this->createPurchaseOrder($supplier, 'PO dalam proses');
        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_IN_PROGRESS]);

        Livewire::test(ListPurchaseOrders::class)
            ->assertCanSeeTableRecords([$purchaseOrder])
            ->assertSee('Dalam Proses');
    }

    private function createPurchaseOrder(Supplier $supplier, string $title): PurchaseOrder
    {
        return PurchaseOrder::create([
            'po_date' => '2026-10-03',
            'division_id' => $this->gaDivision->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC GA',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'title' => $title,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $this->gaMaker->id,
        ]);
    }
}
