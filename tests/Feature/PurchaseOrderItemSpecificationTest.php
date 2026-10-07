<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga aturan: satu barang boleh dipakai dengan ukuran/spesifikasi berbeda
 * tiap pembelian (mis. POLYBAG), jadi spesifikasi yang diketik user di baris PO
 * tidak boleh ditimpa nilai master barang.
 */
class PurchaseOrderItemSpecificationTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrder $po;

    private Item $master;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['code' => 'GA', 'name' => 'General Affairs', 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'SUP-SPESIFIKASI', 'name' => 'Vendor Uji Spesifikasi', 'is_active' => true]);
        $pembuat = User::factory()->create(['division_id' => $division->id]);

        $this->po = PurchaseOrder::create([
            'po_number' => 'PO/HIJ/07102026-000099',
            'company_code' => 'HIJ',
            'sequence_number' => 99,
            'po_date' => '2026-10-07',
            'division_id' => $division->id,
            'supplier_id' => $supplier->id,
            'pic_name' => 'PIC GA',
            'currency' => PurchaseOrder::CURRENCY_IDR,
            'status' => PurchaseOrder::STATUS_NEW,
            'created_by' => $pembuat->id,
        ]);

        $this->master = Item::create([
            'name' => 'POLYBAG PE',
            'specification' => '0.04 x 90 x 130',
        ]);
    }

    private function buatBaris(array $tambahan = []): PurchaseOrderItem
    {
        return PurchaseOrderItem::create(array_merge([
            'purchase_order_id' => $this->po->id,
            'item_id' => $this->master->id,
            'item_name' => $this->master->name,
            'quantity' => 1,
            'unit_price_amount' => 1000,
        ], $tambahan));
    }

    public function test_spesifikasi_yang_diketik_user_tidak_ditimpa_nilai_master(): void
    {
        $baris = $this->buatBaris(['specification' => '0,06 x 100 x 150']);
        $tersimpan = PurchaseOrderItem::query()->find($baris->id);

        $this->assertSame(
            '0,06 x 100 x 150',
            $tersimpan->specification,
            'Spesifikasi yang diketik user tertimpa nilai master barang.'
        );

        // Spesifikasi master tetap tercatat sebagai acuan historis.
        $this->assertSame('0.04 x 90 x 130', $tersimpan->specification_snapshot);
    }

    public function test_spesifikasi_diisi_dari_master_bila_kolomnya_kosong(): void
    {
        $tersimpan = PurchaseOrderItem::query()->find($this->buatBaris()->id);

        $this->assertSame('0.04 x 90 x 130', $tersimpan->specification);
    }

    public function test_spesifikasi_yang_diketik_user_bertahan_saat_baris_disimpan_ulang(): void
    {
        $baris = $this->buatBaris(['specification' => '0,08 x 110 x 160']);

        $baris->quantity = 3;
        $baris->save();

        $this->assertSame('0,08 x 110 x 160', $baris->fresh()->specification);
    }

    public function test_pdf_mencetak_spesifikasi_baris_bukan_spesifikasi_master(): void
    {
        $this->buatBaris(['specification' => '0,06 x 100 x 150']);

        $html = view('pdf.purchase-order', [
            'purchaseOrder' => $this->po->fresh(['items.unit', 'supplier', 'taxes', 'division']),
        ])->render();

        $this->assertStringContainsString(
            '0,06 x 100 x 150',
            $html,
            'PDF tidak mencetak ukuran yang diketik user pada baris PO.'
        );
        $this->assertStringNotContainsString(
            '0.04 x 90 x 130',
            $html,
            'PDF masih mencetak ukuran dari master barang, bukan ukuran pada baris PO.'
        );
    }
}
