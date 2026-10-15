<?php

namespace Tests\Feature;

use App\Actions\VerifyPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\Buyer;
use App\Models\ChartOfAccount;
use App\Models\Division;
use App\Models\DocumentFile;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\Erp\ErpJournalBuilder;
use App\Services\GeminiInvoiceExtractor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentSlipConventionalEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Supplier $supplier;

    private Buyer $buyer;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::create(['name' => 'maker']);
        Role::create(['name' => 'checker']);
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $this->maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $this->supplier = Supplier::create(['code' => 'SUP-BLADE', 'name' => 'Supplier Blade']);
        $this->buyer = Buyer::create(['code' => 'BUY-BLADE', 'name' => 'Buyer Blade']);
        $this->unit = Unit::create(['code' => 'PCS', 'name' => 'Pieces']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->maker);
    }

    private function payload(string $currency = 'IDR'): array
    {
        return [
            'supplier_id' => $this->supplier->id, 'currency' => $currency,
            'transaction_description' => 'Operasional',
            'invoices' => [[
                'buyer_id' => $this->buyer->id, 'invoice_number' => 'INV-BLADE',
                'invoice_date' => '2026-10-13', 'tax_calculation_mode' => 'itemized',
                'items' => [['item_name' => 'Barang', 'quantity' => 2, 'unit_price_amount' => 12.25, 'unit_id' => $this->unit->id]],
            ]],
        ];
    }

    private function createSlip(string $type, array $payload = []): PaymentSlip
    {
        $response = $this->post(route('payment-slips.editor.store', $type), $payload ?: $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect();

        $slip = PaymentSlip::latest('id')->firstOrFail();
        $response->assertRedirect(PaymentSlipResource::getUrl('view', ['record' => $slip]));

        return $slip;
    }

    public function test_active_create_and_edit_routes_render_blade_for_all_types(): void
    {
        foreach (['general', 'import', 'export'] as $type) {
            $response = $this->get(PaymentSlipResource::getUrl('create-'.$type));
            $response->assertOk()->assertSee('data-payment-slip-editor', false)->assertSee('data-ps-picker', false)
                ->assertSee('data-ps-view="split"', false)->assertSee('data-ps-divider', false)
                ->assertSee('data-ps-overview', false)->assertSee('payment-slip-detail__overview-total', false)->assertSee('Total Amount');
            $type === 'general'
                ? $response->assertDontSee('data-ps-extract-files', false)
                : $response->assertSee('data-ps-extract-files', false)
                    ->assertSee('aria-controls="ps-extraction-panel"', false)->assertSee('data-ps-extract-progress', false)
                    ->assertSee('ps-editor__verification-help', false)->assertSee('materai')
                    ->assertSee('data-ps-add-item', false)->assertSee('data-ps-preview', false);
            $slip = $this->createSlip($type);
            $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))
                ->assertOk()->assertSee('data-payment-slip-editor', false)->assertSee('INV-BLADE')
                ->assertSee('data-ps-overview', false)->assertSee('Total Amount');
            $this->assertSame($type, $slip->transaction_type);
            $this->assertSame($type === 'general' ? '25.00' : '24.50', $slip->grand_total_amount);
        }
    }

    public function test_create_requires_general_unit_and_exim_buyer_and_keeps_input_on_failure(): void
    {
        $data = $this->payload();
        unset($data['invoices'][0]['items'][0]['unit_id']);
        $this->from(PaymentSlipResource::getUrl('create-general'))->post(route('payment-slips.editor.store', 'general'), $data)
            ->assertSessionHasErrors('invoices.0.items.0.unit_id')->assertSessionHasInput('invoices.0.invoice_number', 'INV-BLADE');
        $data = $this->payload();
        unset($data['invoices'][0]['buyer_id']);
        $this->post(route('payment-slips.editor.store', 'export'), $data)->assertSessionHasErrors('invoices.0.buyer_id');
        $this->assertDatabaseCount('payment_slips', 0);
    }

    public function test_export_supporting_supplier_round_trips_in_both_tax_modes_without_changing_erp(): void
    {
        $checker = User::factory()->create()->assignRole('checker');
        $coa = ChartOfAccount::create(['code' => '43011011', 'name' => 'QA Account', 'is_active' => true]);
        foreach (['itemized', 'invoice_legacy'] as $mode) {
            $this->actingAs($this->maker);
            $data = $this->payload();
            $data['invoices'][0]['items'][0]['unit_price_amount'] = 100;
            $data['invoices'][0]['tax_calculation_mode'] = $mode;
            $data['invoices'][0]['items'][0]['source_supplier_name'] = 'Pendukung & Cabang <QA>';
            $slip = $this->createSlip('export', $data);
            $invoice = $slip->invoices()->sole();
            $item = $invoice->items()->sole();
            $this->assertSame('Pendukung & Cabang <QA>', $item->source_supplier_name);
            $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))->assertOk()
                ->assertSee('Supplier Pendukung')->assertSee('Pendukung &amp; Cabang &lt;QA&gt;', false)
                ->assertDontSee('data-ps-import-field', false);
            $data['invoices'][0]['id'] = $invoice->id;
            $data['invoices'][0]['items'][0]['id'] = $item->id;
            $data['invoices'][0]['items'][0]['source_supplier_name'] = 'Pendukung Baru';
            $before = $slip->grand_total_amount;
            $this->put(route('payment-slips.editor.update', $slip), $data)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($before, $slip->fresh()->grand_total_amount);
            $this->assertSame($mode, $invoice->fresh()->tax_calculation_mode);
            $this->get(PaymentSlipResource::getUrl('view', ['record' => $slip]))->assertOk()->assertSee('Pendukung Baru');
            $data['submit_after_save'] = true;
            $this->put(route('payment-slips.editor.update', $slip), $data)->assertRedirect()->assertSessionHasNoErrors();
            $this->actingAs($checker)->get(route('payment-slips.checker-edit', $slip))->assertOk()->assertSee('Supplier Pendukung: Pendukung Baru');
            $this->post(route('payment-slips.checker-edit.update', $slip), [
                'items' => [$item->id => ['coa_id' => $coa->id, 'source_supplier_name' => 'tamper']],
            ])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('Pendukung Baru', $item->fresh()->source_supplier_name);
            app(VerifyPaymentSlip::class)->execute($slip, $checker);
            $withSupplier = app(ErpJournalBuilder::class)->build($slip->fresh());
            DB::table('invoice_items')->where('id', $item->id)->update(['source_supplier_name' => null]);
            $withoutSupplier = app(ErpJournalBuilder::class)->build($slip->fresh());
            $this->assertEquals($withoutSupplier, $withSupplier);
        }
    }

    public function test_exim_type_selector_and_create_another_keep_the_selected_type(): void
    {
        $data = $this->payload('EUR') + ['transaction_type' => 'import', 'create_another' => true];
        $this->post(route('payment-slips.editor.store', 'export'), $data)
            ->assertRedirect(PaymentSlipResource::getUrl('create-import'))->assertSessionHasNoErrors();
        $slip = PaymentSlip::firstOrFail();
        $this->assertSame('import', $slip->transaction_type);
        $this->assertSame('IDR', $slip->currency);
        $this->post(route('payment-slips.editor.store', 'general'), $data)->assertSessionHasErrors('transaction_type');
    }

    public function test_edit_recalculates_currency_and_can_clear_tax_and_submit(): void
    {
        $tax = Tax::create(['code' => 'T11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $data = $this->payload('EUR');
        $data['invoices'][0]['items'][0]['ppn_tax_id'] = $tax->id;
        $slip = $this->createSlip('general', $data);
        $this->assertSame('27.20', $slip->grand_total_amount);
        $invoice = $slip->invoices()->firstOrFail();
        $data['invoices'][0]['id'] = $invoice->id;
        $data['invoices'][0]['items'][0]['id'] = $invoice->items()->firstOrFail()->id;
        $data['currency'] = 'IDR';
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertSame('28.00', $slip->fresh()->grand_total_amount);
        $data['invoices'][0]['items'][0]['ppn_tax_id'] = null;
        $data['submit_after_save'] = true;
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors()->assertRedirect(PaymentSlipResource::getUrl('view', ['record' => $slip]));
        $this->assertSame('25.00', $slip->fresh()->grand_total_amount);
        $this->assertSame('submitted', $slip->fresh()->status);
    }

    public function test_general_historical_invoice_tax_mode_survives_edit(): void
    {
        $slip = $this->createSlip('general');
        $invoice = $slip->invoices()->firstOrFail();
        $tax = Tax::create(['code' => 'L11', 'name' => 'Legacy PPN', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        DB::table('invoices')->where('id', $invoice->id)->update(['tax_calculation_mode' => 'invoice_legacy', 'ppn_tax_id' => $tax->id]);
        $data = $this->payload();
        $data['invoices'][0]['id'] = $invoice->id;
        $data['invoices'][0]['ppn_tax_id'] = $tax->id;
        $data['invoices'][0]['items'][0]['id'] = $invoice->items()->firstOrFail()->id;
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertSame('invoice_legacy', $invoice->fresh()->tax_calculation_mode);
        $this->assertSame('28.00', $slip->fresh()->grand_total_amount);
        $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))->assertOk()->assertSee('value="invoice_legacy" data-ps-tax-mode', false);
    }

    public function test_invoice_mode_switch_clears_the_previous_tax_ids(): void
    {
        $tax = Tax::create(['code' => 'S10', 'name' => 'PPN 10%', 'rate' => 10, 'calculation_type' => 'addition', 'is_active' => true]);
        $data = $this->payload();
        $data['invoices'][0]['items'][0]['ppn_tax_id'] = $tax->id;
        $slip = $this->createSlip('export', $data);
        $invoice = $slip->invoices()->firstOrFail();
        $item = $invoice->items()->firstOrFail();
        $data['invoices'][0]['id'] = $invoice->id;
        $data['invoices'][0]['items'][0]['id'] = $item->id;
        $data['invoices'][0]['tax_calculation_mode'] = 'invoice_legacy';
        $data['invoices'][0]['ppn_tax_id'] = $tax->id;
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->ppn_tax_id);
        $data['invoices'][0]['tax_calculation_mode'] = 'itemized';
        unset($data['invoices'][0]['ppn_tax_id'], $data['invoices'][0]['items'][0]['ppn_tax_id']);
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertNull($invoice->fresh()->ppn_tax_id);
        $this->assertSame('24.50', $slip->fresh()->grand_total_amount);
    }

    public function test_extraction_preview_returns_editable_data_without_saving_a_slip(): void
    {
        $this->mock(GeminiInvoiceExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractWithReport')->once()->andReturnUsing(fn (array $files) => [
                'successful' => [[
                    'path' => $files[0]['path'], 'original_name' => $files[0]['original_name'],
                    'invoices' => [['invoice_number' => 'GEMINI-1', 'invoice_date' => '2026-10-13', 'items' => [['item_name' => 'Freight', 'unit_price' => 100, 'line_total' => 111]]]],
                ]], 'failed' => [],
            ]);
        });
        $response = $this->postJson(route('payment-slips.editor.extract', 'export'), [
            'raw_pdf_files' => [UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')],
        ])->assertOk()->assertJsonPath('invoices.0.items.0.unit_price_amount', 100)->assertJsonPath('invoices.0.tax_calculation_mode', 'itemized');
        $this->assertDatabaseCount('payment_slips', 0);
        $this->assertDatabaseCount('invoices', 0);
        $data = $this->payload();
        $data['invoices'][0] = $response->json('invoices.0');
        $data['invoices'][0]['buyer_id'] = $this->buyer->id;
        $data['invoices'][0]['items'][] = ['item_name' => 'Materai', 'quantity' => 1, 'unit_price_amount' => 10000];
        $slip = $this->createSlip('export', $data);
        $invoice = $slip->invoices()->firstOrFail();
        $this->assertSame($response->json('invoices.0.document_file_id'), $invoice->document_file_id);
        $this->assertSame('10100.00', $slip->grand_total_amount);
        $this->assertSame(['Freight', 'Materai'], $invoice->items()->orderBy('id')->pluck('item_name')->all());
        $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))
            ->assertOk()->assertSee('Materai')->assertSee('Preview PDF');
        $data['invoices'][0]['id'] = $invoice->id;
        foreach ($invoice->items()->orderBy('id')->get() as $index => $item) {
            $data['invoices'][0]['items'][$index]['id'] = $item->id;
        }
        $data['invoices'][0]['items'][1]['unit_price_amount'] = 12000;
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertSame('12100.00', $slip->fresh()->grand_total_amount);
        $this->assertSame($invoice->document_file_id, $invoice->fresh()->document_file_id);
    }

    public function test_document_upload_and_master_unit_creation(): void
    {
        $this->postJson(route('payment-slips.editor.master', 'unit'), ['code' => 'BOX', 'name' => 'Box'])
            ->assertCreated()->assertJsonPath('label', 'Box');
        $data = $this->payload();
        $data['invoices'][0]['document_pdf'] = UploadedFile::fake()->create('manual.pdf', 10, 'application/pdf');
        $slip = $this->createSlip('general', $data);
        $document = $slip->invoices()->firstOrFail()->documentFile;
        $this->assertSame('manual.pdf', $document->original_name);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_cannot_attach_unrelated_document_or_change_another_makers_draft(): void
    {
        $data = $this->payload();
        $document = DocumentFile::create(['disk' => 'local', 'path' => 'other.pdf', 'original_name' => 'other.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'uploaded_at' => now()]);
        $data['invoices'][0]['document_file_id'] = $document->id;
        $this->post(route('payment-slips.editor.store', 'general'), $data)->assertForbidden();
        $this->assertDatabaseCount('payment_slips', 0);
        $slip = $this->createSlip('export');
        $other = User::factory()->create(['division_id' => $this->maker->division_id])->assignRole('maker');
        $this->actingAs($other)->put(route('payment-slips.editor.update', $slip), $this->payload())->assertForbidden();
        $this->postJson(route('payment-slips.editor.extract', 'export'), ['payment_slip_id' => $slip->id])->assertForbidden();
    }

    public function test_checker_cannot_submit_or_mutate_a_submitted_slip_via_draft_endpoint(): void
    {
        $slip = $this->createSlip('general');
        $checker = User::factory()->create()->assignRole('checker');
        $this->actingAs($checker);
        $data = $this->payload() + ['submit_after_save' => true];
        $data['transaction_description'] = 'Must not change';
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertForbidden();
        $this->assertSame('Operasional', $slip->fresh()->transaction_description);
        $slip->update(['status' => 'submitted']);
        $this->put(route('payment-slips.editor.update', $slip), $this->payload())->assertForbidden();
    }

    public function test_edit_preserves_an_existing_inactive_tax_but_rejects_new_use(): void
    {
        $tax = Tax::create(['code' => 'OLD11', 'name' => 'Old PPN', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $data = $this->payload();
        $data['invoices'][0]['items'][0]['ppn_tax_id'] = $tax->id;
        $slip = $this->createSlip('general', $data);
        $invoice = $slip->invoices()->firstOrFail();
        $data['invoices'][0]['id'] = $invoice->id;
        $data['invoices'][0]['items'][0]['id'] = $invoice->items()->firstOrFail()->id;
        $tax->update(['is_active' => false]);
        $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))->assertOk()->assertSee('Old PPN');
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasNoErrors();
        $this->assertSame('28.00', $slip->fresh()->grand_total_amount);
        $data['invoices'][0]['items'][] = ['item_name' => 'New row', 'quantity' => 1, 'unit_price_amount' => 100, 'unit_id' => $this->unit->id, 'ppn_tax_id' => $tax->id];
        $this->put(route('payment-slips.editor.update', $slip), $data)->assertSessionHasErrors('invoices.0.items.1.ppn_tax_id');
    }

    public function test_failed_extraction_returns_actionable_error_and_no_invoice(): void
    {
        $this->mock(GeminiInvoiceExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractWithReport')->once()->andReturn(['successful' => [], 'failed' => [['original_name' => 'unreadable.pdf', 'message' => 'Unreadable']]]);
        });
        $this->postJson(route('payment-slips.editor.extract', 'import'), [
            'raw_pdf_files' => [UploadedFile::fake()->create('unreadable.pdf', 10, 'application/pdf')],
        ])->assertUnprocessable()->assertJsonPath('failed.0.original_name', 'unreadable.pdf');
        $this->assertDatabaseCount('invoices', 0);
        $this->postJson(route('payment-slips.editor.extract', 'import'), [
            'raw_pdf_files' => [UploadedFile::fake()->create('wrong.txt', 10, 'text/plain')],
        ])->assertUnprocessable()->assertJsonValidationErrors('raw_pdf_files.0');
    }

    public function test_invoice_tax_mode_cannot_discard_import_supplier_or_item_vat_details(): void
    {
        foreach (['source_supplier_name', 'vat_invoice_number'] as $field) {
            $data = $this->payload();
            $data['invoices'][0]['tax_calculation_mode'] = 'invoice_legacy';
            $data['invoices'][0]['items'][0][$field] = 'Detail must survive';
            $this->post(route('payment-slips.editor.store', 'import'), $data)
                ->assertSessionHasErrors('invoices.0.tax_calculation_mode');
            $this->assertDatabaseCount('payment_slips', 0);
        }
    }
}
