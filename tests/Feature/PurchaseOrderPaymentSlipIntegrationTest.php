<?php

namespace Tests\Feature;

use App\Actions\GeneratePaymentSlipPdf;
use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlipEditor;
use App\Filament\Resources\PurchaseOrders\Pages\IssuePaymentSlip;
use App\Models\ChartOfAccount;
use App\Models\Division;
use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\PaymentSlip;
use App\Models\PaymentSlipAudit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentSlips\CreatePaymentSlip;
use App\Services\PaymentSlips\SavePaymentSlipEdit;
use App\Services\PurchaseOrders\FinalizePurchaseOrder;
use App\Services\PurchaseOrders\IssueGeneralPaymentSlipFromPurchaseOrder;
use App\Services\PurchaseOrders\SavePurchaseOrder;
use App\Support\CurrencyFormatter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderPaymentSlipIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Supplier $supplier;

    private Unit $unit;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'maker']);
        Role::create(['name' => 'checker']);
        $division = Division::create(['code' => 'GA', 'name' => 'GA', 'is_active' => true]);
        $this->maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $this->supplier = Supplier::create(['code' => 'PO-QA', 'name' => 'Vendor QA', 'is_active' => true]);
        $this->unit = Unit::create(['code' => 'BOX', 'name' => 'Box', 'is_active' => true]);
        $this->item = Item::create(['name' => 'Polybag PE', 'specification' => 'Master', 'unit_id' => $this->unit->id, 'is_active' => true]);
        $this->actingAs($this->maker);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function po(string $currency = 'IDR', int $count = 2, bool $taxes = true): PurchaseOrder
    {
        $addition = $taxes ? Tax::firstOrCreate(['code' => 'PPN-QA'], ['name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]) : null;
        $deduction = $taxes ? Tax::firstOrCreate(['code' => 'PPH-QA'], ['name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]) : null;

        return app(SavePurchaseOrder::class)->execute([
            'supplier_id' => $this->supplier->id, 'pic_user_id' => $this->maker->id,
            'po_date' => '2026-10-14', 'currency' => $currency, 'status' => 'new',
            'discount_amount' => 3.5, 'shipping_amount' => 7.25,
            'addition_tax_id' => $addition?->id, 'deduction_tax_id' => $deduction?->id,
            'items' => array_fill(0, $count, ['item_id' => $this->item->id, 'specification' => '20 x 30', 'unit_id' => $this->unit->id, 'quantity' => 1.5, 'unit_price_amount' => 105.67]),
        ], $this->maker);
    }

    private function issue(PurchaseOrder $po): PaymentSlip
    {
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);

        return app(IssueGeneralPaymentSlipFromPurchaseOrder::class)->execute($po, $this->maker, [
            'transaction_description' => 'Pembelian barang', 'invoice_number' => 'INV-SUPPLIER-001', 'invoice_date' => '2026-10-14',
        ]);
    }

    public function test_idr_usd_mapping_totals_and_snapshot_match_each_component(): void
    {
        foreach (['IDR', 'USD'] as $currency) {
            foreach ([1, 2, 50] as $count) {
                $po = $this->po($currency, $count);
                $slip = $this->issue($po);
                $invoice = $slip->invoices->sole();
                $this->assertSame($po->id, $invoice->purchase_order_id);
                $this->assertSame($currency, $slip->currency);
                $this->assertSame('draft', $slip->status);
                foreach (['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'discount_amount', 'shipping_amount', 'grand_total_amount'] as $field) {
                    $this->assertSame($po->{$field}, $invoice->{$field}, $currency.' '.$field);
                }
                $this->assertSame($po->grand_total_amount, $slip->grand_total_amount);
                $this->assertCount($count, $invoice->items);
                $this->assertSame('Polybag PE [20 x 30]', $invoice->items->first()->item_name);
                $audit = $po->audits()->where('event', 'payment_slip_issued')->sole();
                $this->assertCount($count, $audit->new_values['items']);
                $this->assertSame($invoice->items->first()->id, $audit->new_values['items'][0]['invoice_item_id']);
                $invoice->update(['vat_invoice_number' => 'VAT-123']);
                $this->assertSame($po->grand_total_amount, $invoice->fresh()->grand_total_amount);
            }
        }
    }

    public function test_po_general_can_be_verified_with_adjustments_without_erp_coa(): void
    {
        $checker = User::factory()->create()->assignRole('checker');
        foreach (['IDR', 'USD'] as $currency) {
            $slip = $this->issue($this->po($currency));
            $slip->update(['status' => 'submitted']);
            $before = $slip->fresh()->only(['subtotal_amount', 'tax_addition_amount', 'tax_deduction_amount', 'grand_total_amount']);
            $invoice = $slip->invoices()->sole();
            $this->actingAs($checker)->get(route('payment-slips.checker-edit', $slip))
                ->assertOk()->assertSee('Referensi PO:')->assertSee($invoice->purchaseOrder->po_number)
                ->assertSee('Diskon (pengurang)')->assertSee('Ongkir')->assertSee('Box')
                ->assertDontSee('Buyer unavailable');
            app(VerifyPaymentSlip::class)->execute($slip, $checker);
            $this->assertSame('approved', $slip->status);
            $this->assertSame($before, $slip->only(array_keys($before)));
            $this->assertSame(1, $slip->audits()->where('event', 'verified')->count());
            $this->actingAs($this->maker);
        }
    }

    public function test_final_reopen_then_issuance_locks_source_permanently(): void
    {
        $po = $this->po();
        $finalize = app(FinalizePurchaseOrder::class);
        $finalize->execute($po, $this->maker);
        $this->assertTrue($po->isSourceLocked());
        $finalize->execute($po, $this->maker, true);
        $this->assertFalse($po->isSourceLocked());
        $slip = $this->issue($po);
        $invoice = $slip->invoices->sole();
        $operations = [
            fn () => $finalize->execute($po, $this->maker, true),
            fn () => $po->update(['notes' => 'tamper']),
            fn () => $po->items->first()->update(['quantity' => 100]),
            fn () => $po->delete(), fn () => $slip->delete(), fn () => $invoice->delete(),
            fn () => $invoice->update(['purchase_order_id' => null]),
            fn () => $invoice->items->first()->delete(),
            fn () => $invoice->items->first()->update(['unit_price_amount' => 1]),
            fn () => $slip->update(['currency' => 'EUR']),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('Mutation must be rejected');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertModelExists($po);
        $this->assertModelExists($invoice);
        $this->assertSame(1, PaymentSlip::count());
    }

    public function test_historical_nonfinal_and_duplicate_requests_are_rejected(): void
    {
        $po = $this->po();
        $url = route('purchase-orders.issue-payment-slip', $po);
        $data = ['transaction_description' => 'Pembelian', 'invoice_number' => 'INV-QA', 'invoice_date' => '2026-10-14'];
        $this->post($url, $data)->assertSessionHasErrors('purchase_order');
        $po->update(['source' => 'ecount', 'source_code' => '06/10/2026 -1']);
        try {
            app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
            $this->fail();
        } catch (ValidationException) {
            $this->assertSame(0, PaymentSlip::count());
        }
        $po = $this->po();
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
        $url = route('purchase-orders.issue-payment-slip', $po);
        $this->post($url, $data + ['currency' => 'EUR'])->assertSessionHasErrors('currency');
        $this->post($url, $data)->assertRedirect();
        $this->post($url, $data)->assertSessionHasErrors('purchase_order');
        $this->assertSame(1, PaymentSlip::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_preview_is_read_only_and_edit_has_metadata_form_without_item_inputs(): void
    {
        $po = $this->po();
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
        Livewire::test(IssuePaymentSlip::class, ['record' => $po->id])->assertSee('Simpan Draft Payment Slip')->assertSee('20 x 30');
        $this->assertSame(0, PaymentSlip::count());
        $slip = $this->issue($po);
        $html = Livewire::test(EditPaymentSlipEditor::class, ['record' => $slip->id])->html();
        $this->assertStringContainsString('Referensi PO', $html);
        $this->assertStringNotContainsString('[quantity]', $html);
        $this->assertStringNotContainsString('data-ps-add-invoice', $html);
        $this->assertLessThan(1_000_000, strlen($html));
        $invoice = $slip->invoices->sole();
        $data = ['transaction_description' => 'Pembelian', 'invoices' => [['id' => $invoice->id, 'invoice_number' => 'INV-CORRECT', 'invoice_date' => '2026-10-15']]];
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('INV-CORRECT', $invoice->fresh()->invoice_number);
        $this->assertSame($po->grand_total_amount, $slip->fresh()->grand_total_amount);
        $data['invoices'][0]['items'] = [];
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasErrors();
    }

    public function test_po_issued_slip_accepts_optional_approval_names_and_edit_keeps_po_data_locked(): void
    {
        $po = $this->po();
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
        Livewire::test(IssuePaymentSlip::class, ['record' => $po->id])
            ->assertSee('Nama Persetujuan')->assertSee('Passed 1 (opsional)')
            ->assertSee('name="approval_director" maxlength="255" value="Mr DJ Kim"', false);

        $this->post(route('purchase-orders.issue-payment-slip', $po), [
            'transaction_description' => 'Pembelian barang',
            'invoice_number' => 'INV-APPROVAL',
            'invoice_date' => '2026-10-14',
            'approval_incharge' => 'Admin GA',
            'approval_passed_1' => 'Kepala GA',
            'approval_director' => 'Direktur PO',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $slip = PaymentSlip::latest('id')->firstOrFail();
        $this->assertSame('Admin GA', $slip->approval_incharge);
        $this->assertSame('Kepala GA', $slip->approval_passed_1);
        $this->assertSame('Direktur PO', $slip->approval_director);
        $this->assertNull($slip->approval_passed_2);
        $this->assertSame($this->maker->name, $slip->creator->name);

        $invoice = $slip->invoices()->sole();
        $this->put(route('payment-slips.editor.update', $slip), [
            'transaction_description' => 'Pembelian barang',
            'approval_incharge' => 'Admin GA Baru',
            'approval_passed_1' => 'Kepala GA Baru',
            'approval_director' => '',
            'invoices' => [[
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Admin GA Baru', $slip->fresh()->approval_incharge);
        $this->assertSame('Kepala GA Baru', $slip->fresh()->approval_passed_1);
        $this->assertNull($slip->fresh()->approval_director);
        $this->assertSame($po->grand_total_amount, $slip->fresh()->grand_total_amount);
        $this->assertSame($po->id, $invoice->fresh()->purchase_order_id);
    }

    public function test_wrong_role_is_forbidden_and_failed_issuance_rolls_back(): void
    {
        $po = $this->po();
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
        $other = User::factory()->create()->assignRole('checker');
        $this->actingAs($other)->post(route('purchase-orders.issue-payment-slip', $po), ['transaction_description' => 'Test', 'invoice_number' => 'X', 'invoice_date' => '2026-10-14'])->assertForbidden();
        $this->actingAs($this->maker);
        PaymentSlipAudit::creating(fn () => throw new \RuntimeException('Simulated storage failure'));
        try {
            $this->issue($po);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated storage failure', $e->getMessage());
        }
        $this->assertSame(0, PaymentSlip::count());
        $this->assertSame(0, Invoice::count());
        $this->assertFalse($po->hasIssuedPaymentSlip());
    }

    public function test_pdf_reference_and_manual_adjustments(): void
    {
        $po = $this->po('USD');
        $slip = $this->issue($po);
        $html = view('pdf.payment-slips.general', ['slip' => $slip])->render();
        $this->assertMatchesRegularExpression('/<table class="data-table"[^>]*>.*Referensi PO.*'.preg_quote($po->po_number, '/').'.*<\/table>/s', $html);
        $detailTable = substr($html, strpos($html, '<table class="detail-table">'));
        $this->assertStringNotContainsString('Referensi PO', $detailTable);
        $this->assertStringNotContainsString('INV-SUPPLIER-001', $detailTable);
        $this->assertStringNotContainsString('No. Invoice:', $detailTable);
        $this->assertStringContainsString('INV-SUPPLIER-001', $html);
        $this->assertStringContainsString('Diskon', $html);
        $this->assertStringContainsString(CurrencyFormatter::format($po->grand_total_amount, 'USD'), $html);
        $pdf = app(GeneratePaymentSlipPdf::class)->execute($slip);
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        $this->assertSame(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        foreach (['IDR', 'USD', 'EUR'] as $currency) {
            $manual = app(CreatePaymentSlip::class)->execute('general', [
                'supplier_id' => $this->supplier->id, 'currency' => $currency, 'transaction_description' => 'Manual',
                'invoices' => array_map(fn ($index) => ['invoice_number' => 'MANUAL-'.$index, 'invoice_date' => '2026-10-14', 'discount_amount' => 10, 'shipping_amount' => 20,
                    'items' => [['item_name' => 'Manual Item', 'quantity' => 1, 'unit_price_amount' => 100, 'unit_id' => $this->unit->id]]], [1, 2]),
            ], app(SavePaymentSlipEdit::class));
            $this->assertSame('220.00', $manual->grand_total_amount);
            $manualHtml = view('pdf.payment-slips.general', ['slip' => $manual])->render();
            $this->assertStringNotContainsString('Referensi PO:', $manualHtml);
            $this->assertStringContainsString('MANUAL-1, MANUAL-2', $manualHtml);
            $this->assertStringNotContainsString('MANUAL-1', substr($manualHtml, strpos($manualHtml, '<table class="detail-table">')));
        }
    }

    public function test_pdf_hides_each_zero_adjustment_independently(): void
    {
        foreach ([[0, 0], [10, 0], [0, 20], [10, 20]] as [$discount, $shipping]) {
            $po = $this->po();
            $po->update(['discount_amount' => $discount, 'shipping_amount' => $shipping]);
            $po->recalculateTotals();
            $slip = $this->issue($po->fresh());
            $html = view('pdf.payment-slips.general', ['slip' => $slip])->render();
            $this->assertSame($discount > 0, str_contains($html, 'Diskon (-)'));
            $this->assertSame($shipping > 0, str_contains($html, 'Ongkos Kirim (+)'));
            $this->assertStringContainsString(CurrencyFormatter::format($slip->grand_total_amount, $slip->currency), $html);
            if (getenv('PO_PDF_QA_OUTPUT') && $discount === 0 && $shipping === 0) {
                app(GeneratePaymentSlipPdf::class)->execute($slip)->save(getenv('PO_PDF_QA_OUTPUT'));
            }
        }
    }

    public function test_checker_accounting_save_preserves_source_and_adjustments(): void
    {
        $po = $this->po('USD');
        $slip = $this->issue($po);
        $slip->update(['status' => 'submitted']);
        $checker = User::factory()->create()->assignRole('checker');
        $coa = ChartOfAccount::create(['code' => 'QA-COA', 'name' => 'QA expense', 'is_active' => true]);
        $invoice = $slip->invoices->sole();
        $item = $invoice->items->first();
        $this->actingAs($checker)->post(route('payment-slips.checker-edit.update', $slip), [
            'invoices' => [$invoice->id => ['vat_invoice_number' => 'VAT-QA']],
            'items' => [$item->id => ['coa_id' => $coa->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($coa->id, $item->fresh()->coa_id);
        $this->assertSame($po->discount_amount, $invoice->fresh()->discount_amount);
        $this->assertSame($po->shipping_amount, $invoice->fresh()->shipping_amount);
        $this->assertSame($po->grand_total_amount, $slip->fresh()->grand_total_amount);
    }

    public function test_rollback_removes_only_the_new_upload(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('keep-existing.pdf', 'existing document');
        $po = $this->po();
        app(FinalizePurchaseOrder::class)->execute($po, $this->maker);
        PaymentSlipAudit::creating(fn () => throw new \RuntimeException('Rollback QA'));
        try {
            app(IssueGeneralPaymentSlipFromPurchaseOrder::class)->execute($po, $this->maker, [
                'transaction_description' => 'QA', 'invoice_number' => 'QA', 'invoice_date' => '2026-10-14',
                'document_pdf' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
            ]);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Rollback QA', $e->getMessage());
        }
        $this->assertSame(0, PaymentSlip::count());
        $this->assertSame(0, DocumentFile::count());
        $this->assertSame(['keep-existing.pdf'], Storage::disk('local')->allFiles());
    }

    public function test_invalid_adjustments_and_forged_reference_are_rejected(): void
    {
        $data = [
            'supplier_id' => $this->supplier->id, 'currency' => 'IDR', 'transaction_description' => 'Manual',
            'form_complete' => '1',
            'invoices' => [['invoice_number' => 'MANUAL', 'invoice_date' => '2026-10-14', 'tax_calculation_mode' => 'itemized', 'discount_amount' => 1000,
                'items' => [['item_name' => 'Item', 'quantity' => 1, 'unit_price_amount' => 100, 'unit_id' => $this->unit->id]]]],
        ];
        $url = route('payment-slips.editor.store', ['type' => 'general']);
        $this->post($url, $data)->assertSessionHasErrors('invoices');
        $data['invoices'][0]['discount_amount'] = -1;
        $this->post($url, $data)->assertSessionHasErrors('invoices.0.discount_amount');
        $data['invoices'][0]['discount_amount'] = 0;
        $data['invoices'][0]['purchase_order_id'] = $this->po()->id;
        $this->post($url, $data)->assertSessionHasErrors('invoices.0.purchase_order_id');
        $this->assertSame(0, PaymentSlip::count());
    }
}
