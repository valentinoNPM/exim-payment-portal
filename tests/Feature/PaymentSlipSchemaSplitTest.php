<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\CreateExportPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\CreateGeneralPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\Customer;
use App\Models\Division;
use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentSlipSchemaSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'maker']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_general_and_exim_create_pages_build_only_their_own_top_level_fields(): void
    {
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $this->actingAs($maker);

        $general = $this->fieldNames(CreateGeneralPaymentSlip::class);
        $exim = $this->fieldNames(CreateExportPaymentSlip::class);

        $this->assertContains('currency', $general);
        $this->assertContains('transaction_description', $general);
        $this->assertContains('customer_id', $general);
        $this->assertNotContains('invoice_receipt_number', $general);
        $this->assertNotContains('extract_via_gemini', $general);

        $this->assertContains('invoice_receipt_number', $exim);
        $this->assertContains('extract_via_gemini', $exim);
        $this->assertNotContains('currency', $exim);
        $this->assertNotContains('transaction_description', $exim);
        $this->assertNotContains('customer_id', $exim);
    }

    public function test_existing_record_type_selects_the_matching_edit_and_view_schema(): void
    {
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-SPLIT', 'name' => 'Supplier Split']);
        $this->actingAs($maker);

        $general = PaymentSlip::create([
            'slip_number' => 'PS-SPLIT-GEN',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'supplier_id' => $supplier->id,
            'created_by' => $maker->id,
            'status' => 'draft',
        ]);
        $exim = PaymentSlip::create([
            'slip_number' => 'PS-SPLIT-EXIM',
            'transaction_type' => PaymentSlip::TYPE_EXPORT,
            'supplier_id' => $supplier->id,
            'created_by' => $maker->id,
            'status' => 'draft',
        ]);

        $this->assertContains('customer_id', $this->fieldNames(EditPaymentSlip::class, ['record' => $general->getRouteKey()]));
        $this->assertNotContains('customer_id', $this->fieldNames(EditPaymentSlip::class, ['record' => $exim->getRouteKey()]));
    }

    public function test_existing_general_and_exim_detail_pages_render_read_only_text_instead_of_form_controls(): void
    {
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-VIEW', 'name' => 'Supplier View']);
        $customer = Customer::create(['code' => 'CUST-VIEW', 'name' => 'Customer View']);
        $this->actingAs($maker);

        foreach ([PaymentSlip::TYPE_GENERAL, PaymentSlip::TYPE_IMPORT] as $type) {
            $slip = PaymentSlip::create([
                'slip_number' => 'PS-VIEW-'.strtoupper($type),
                'transaction_type' => $type,
                'supplier_id' => $supplier->id,
                'customer_id' => $type === PaymentSlip::TYPE_GENERAL ? $customer->id : null,
                'transaction_description' => $type === PaymentSlip::TYPE_GENERAL ? 'Operasional kantor' : null,
                'invoice_receipt_number' => $type === PaymentSlip::TYPE_IMPORT ? 'HIJ/001' : null,
                'currency' => $type === PaymentSlip::TYPE_GENERAL ? PaymentSlip::CURRENCY_EUR : PaymentSlip::CURRENCY_IDR,
                'created_by' => $maker->id,
                'status' => 'draft',
            ]);
            $invoice = Invoice::create([
                'payment_slip_id' => $slip->id,
                'invoice_number' => 'INV-'.$type,
                'invoice_date' => today(),
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_name' => 'Item '.$type,
                'quantity' => 2,
                'unit_price_amount' => 100,
            ]);

            $view = Livewire::test(ViewPaymentSlip::class, ['record' => $slip->getRouteKey()])
                ->assertSee('Transaction Details')
                ->assertSee('Invoices')
                ->assertSee('Invoice 1')
                ->assertSee('Item '.$type)
                ->assertSee('Invoice PDF Viewer');

            $html = $view->html();
            $this->assertStringContainsString('payment-slip-detail__table', $html);
            $this->assertStringContainsString('>Buyer<', $html);
            $this->assertStringContainsString('>Tanggal invoice<', $html);
            $this->assertStringContainsString('>VAT Invoice No. Utama<', $html);
            $this->assertStringNotContainsString('>Nomor invoice<', $html);
            $this->assertLessThan(
                strpos($html, 'payment-slip-detail__summary'),
                strpos($html, 'payment-slip-detail__table'),
                'The invoice money summary must be rendered after the items table.',
            );
            $this->assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)\b/i', $html);

            if ($type === PaymentSlip::TYPE_GENERAL) {
                $view->assertSee('Keterangan Transaksi')->assertSee('Customer View')->assertSee('EUR')->assertSee('Satuan');
                $view->assertDontSee('Nomor Tanda Terima Invoice');
            } else {
                $view->assertSee('Nomor Tanda Terima Invoice')->assertSee('HIJ/001');
                $view->assertDontSee('Keterangan Transaksi')->assertDontSee('Customer View')->assertDontSee('Satuan');
            }
        }
    }

    public function test_overview_lists_each_invoice_amount_in_order_and_uses_the_stored_slip_total(): void
    {
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-OVERVIEW', 'name' => 'Supplier Overview']);
        $this->actingAs($maker);

        $slip = PaymentSlip::create([
            'slip_number' => 'PS-OVERVIEW',
            'transaction_type' => PaymentSlip::TYPE_EXPORT,
            'supplier_id' => $supplier->id,
            'created_by' => $maker->id,
            'status' => 'draft',
        ]);

        $amounts = [3_383_905, 3_509_282];
        foreach ($amounts as $index => $amount) {
            $invoice = Invoice::create([
                'payment_slip_id' => $slip->id,
                'invoice_number' => 'INV-OVERVIEW-'.($index + 1),
                'invoice_date' => today(),
            ]);
            $invoice->updateQuietly(['grand_total_amount' => $amount]);
        }
        $slip->updateQuietly(['grand_total_amount' => array_sum($amounts)]);

        $html = Livewire::test(ViewPaymentSlip::class, ['record' => $slip->getRouteKey()])
            ->assertSeeInOrder([
                'Invoice 1',
                'Rp 3.383.905',
                'Invoice 2',
                'Rp 3.509.282',
                'Total Amount',
                'Rp 6.893.187',
                'Status',
                'Draft',
            ])
            ->html();

        preg_match_all('/payment-slip-detail__overview-invoice/', $html, $invoiceRows);
        $this->assertCount(2, $invoiceRows[0]);
        $this->assertStringContainsString('payment-slip-detail__status--warning', $html);
        $this->assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)\b/i', $html);
    }

    public function test_document_selector_renders_only_the_selected_pdf_without_losing_its_label(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tests/selected-invoice.pdf', 'pdf');

        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-PDF', 'name' => 'Supplier PDF']);
        $selected = DocumentFile::create([
            'path' => 'tests/selected-invoice.pdf',
            'original_name' => 'selected-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        DocumentFile::create([
            'path' => 'tests/unrelated-invoice.pdf',
            'original_name' => 'unrelated-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $this->actingAs($maker);

        foreach ([PaymentSlip::TYPE_GENERAL, PaymentSlip::TYPE_EXPORT] as $type) {
            $slip = PaymentSlip::create([
                'slip_number' => 'PS-PDF-'.strtoupper($type),
                'transaction_type' => $type,
                'supplier_id' => $supplier->id,
                'created_by' => $maker->id,
                'status' => 'draft',
                'transaction_description' => $type === PaymentSlip::TYPE_GENERAL ? 'Operasional' : null,
            ]);
            $invoice = Invoice::create([
                'payment_slip_id' => $slip->id,
                'invoice_number' => 'INV-'.$type,
                'invoice_date' => today(),
                'document_file_id' => $selected->id,
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_name' => 'Test item',
                'quantity' => 1,
                'unit_price_amount' => 100,
            ]);

            $editor = Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()])
                ->assertSee('Document file')
                ->assertSee('selected-invoice.pdf')
                ->assertDontSee('unrelated-invoice.pdf');

            Livewire::test(ViewPaymentSlip::class, ['record' => $slip->getRouteKey()])
                ->assertSee('selected-invoice.pdf')
                ->assertSee('Preview PDF')
                ->assertDontSee('unrelated-invoice.pdf');

            if ($type === PaymentSlip::TYPE_GENERAL) {
                $editor->call('save')->assertHasNoErrors();
                $this->assertSame($selected->id, $invoice->fresh()->document_file_id);
            }
        }
    }

    public function test_read_only_document_rows_keep_preview_clickable_for_available_and_missing_files(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tests/available-invoice.pdf', 'pdf');

        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-DOC-STATE', 'name' => 'Supplier Document State']);
        $available = DocumentFile::create([
            'path' => 'tests/available-invoice.pdf',
            'original_name' => 'available-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);
        $missing = DocumentFile::create([
            'path' => 'tests/missing-invoice.pdf',
            'original_name' => 'missing-invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);
        $this->actingAs($maker);

        foreach ([PaymentSlip::TYPE_EXPORT, PaymentSlip::TYPE_GENERAL] as $type) {
            $slip = PaymentSlip::create([
                'slip_number' => 'PS-DOCUMENT-STATES-'.strtoupper($type),
                'transaction_type' => $type,
                'supplier_id' => $supplier->id,
                'created_by' => $maker->id,
                'status' => 'draft',
            ]);

            foreach ([$available, $missing] as $index => $document) {
                Invoice::create([
                    'payment_slip_id' => $slip->id,
                    'invoice_number' => 'INV-DOC-'.($index + 1),
                    'invoice_date' => today(),
                    'document_file_id' => $document->id,
                ]);
            }

            $view = Livewire::test(ViewPaymentSlip::class, ['record' => $slip->getRouteKey()])
                ->assertSee('available-invoice.pdf')
                ->assertSee('missing-invoice.pdf')
                ->assertSee('Berkas tidak tersedia')
                ->assertSeeHtml('wire:click="setActivePdf('.$missing->id.')"');

            $html = $view->html();
            preg_match_all('/<button[^>]+title="Preview PDF"(?![^>]+disabled)[^>]*>/i', $html, $previewButtons);
            $this->assertCount(2, $previewButtons[0]);
            $this->assertSame(1, substr_count($html, 'Berkas tidak tersedia'));
            $this->assertSame(route('document-files.view', $available), $view->get('activePdfUrl'));

            $view->call('setActivePdf', $missing->id);
            $this->assertSame(route('document-files.view', $missing), $view->get('activePdfUrl'));
            $this->get(route('document-files.view', $missing))
                ->assertNotFound()
                ->assertSee('Berkas dokumen tidak ditemukan')
                ->assertSee('ID '.$missing->id);
            $this->assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)\b/i', $html);
        }
    }

    public function test_large_exim_editor_preserves_the_filament_baseline_controls(): void
    {
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-PERF', 'name' => 'Supplier Performance']);
        $this->actingAs($maker);

        $slip = PaymentSlip::create([
            'slip_number' => 'PS-PERF-EXPORT',
            'transaction_type' => PaymentSlip::TYPE_EXPORT,
            'supplier_id' => $supplier->id,
            'created_by' => $maker->id,
            'status' => 'draft',
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
        ]);

        $remainingItems = 52;
        for ($invoiceNumber = 1; $invoiceNumber <= 11; $invoiceNumber++) {
            $invoice = Invoice::create([
                'payment_slip_id' => $slip->id,
                'invoice_number' => 'INV-PERF-'.str_pad((string) $invoiceNumber, 2, '0', STR_PAD_LEFT),
                'invoice_date' => today(),
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
            ]);
            $itemsInInvoice = min($remainingItems, $invoiceNumber === 11 ? $remainingItems : 5);
            for ($itemNumber = 1; $itemNumber <= $itemsInInvoice; $itemNumber++) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'item_name' => "Performance item {$invoiceNumber}-{$itemNumber}",
                    'quantity' => 1,
                    'unit_price_amount' => 100,
                ]);
            }
            $remainingItems -= $itemsInInvoice;
        }

        $html = Livewire::test(EditPaymentSlip::class, ['record' => $slip->getRouteKey()])->html();
        preg_match_all('/<[a-z][^>]*>/i', $html, $tags);

        preg_match_all('/<(?:input|select|textarea)\b/i', $html, $controls);

        $this->assertGreaterThan(3000, count($tags[0]), 'The large Filament form unexpectedly stopped rendering its complete schema.');
        $this->assertGreaterThan(1_000_000, strlen($html), 'The large Filament form unexpectedly stopped rendering its complete schema.');
        $this->assertGreaterThan(300, count($controls[0]));
        $this->assertStringContainsString('Tambahkan ke invoices', $html);
        $this->assertStringContainsString('Tambahkan ke items', $html);
        $this->assertStringContainsString('Extract Invoices via Gemini', $html);
        $this->assertMatchesRegularExpression('/(?:Ganti PDF|Upload PDF)/', $html);
        $this->assertStringContainsString('Preview PDF', $html);
        $this->assertStringContainsString('Pajak untuk Seluruh Invoice', $html);
        $this->assertStringContainsString('Terapkan PPN', $html);
        $this->assertStringContainsString('Terapkan PPh', $html);
        $this->assertStringContainsString(
            "->label('Kembali ke pajak per item')",
            file_get_contents(app_path('Filament/Resources/PaymentSlips/Schemas/EximPaymentSlipForm.php')),
        );
        $this->assertStringNotContainsString('payment-slip-editor__', $html);
    }

    public function test_compact_draft_editor_updates_only_rows_owned_by_the_slip(): void
    {
        $maker = User::factory()->create()->assignRole('maker');
        $supplier = Supplier::create(['code' => 'SUP-EDIT', 'name' => 'Supplier Edit']);
        $slip = PaymentSlip::create([
            'slip_number' => 'PS-COMPACT-EDIT',
            'transaction_type' => PaymentSlip::TYPE_GENERAL,
            'transaction_description' => 'Before edit',
            'currency' => PaymentSlip::CURRENCY_IDR,
            'supplier_id' => $supplier->id,
            'created_by' => $maker->id,
            'status' => 'draft',
        ]);
        $invoice = Invoice::create([
            'payment_slip_id' => $slip->id,
            'invoice_number' => 'NOTA-OLD',
            'invoice_date' => today(),
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'item_name' => 'Old item',
            'quantity' => 1,
            'unit_price_amount' => 100,
        ]);

        $this->actingAs($maker)
            ->put(route('payment-slips.editor.update', $slip), [
                'form_complete' => '1',
                'currency' => PaymentSlip::CURRENCY_USD,
                'transaction_description' => 'After edit',
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'id' => $invoice->id,
                    'invoice_number' => 'NOTA-NEW',
                    'invoice_date' => today()->toDateString(),
                    'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                    'items' => [[
                        'id' => $item->id,
                        'item_name' => 'Updated item',
                        'quantity' => 2,
                        'unit_price_amount' => 125,
                    ]],
                ]],
            ])
            ->assertRedirect(PaymentSlipResource::getUrl('edit', ['record' => $slip]))
            ->assertSessionHasNoErrors();

        $this->assertSame('After edit', $slip->fresh()->transaction_description);
        $this->assertSame(PaymentSlip::CURRENCY_USD, $slip->fresh()->currency);
        $this->assertSame('NOTA-NEW', $invoice->fresh()->invoice_number);
        $this->assertSame('Updated item', $item->fresh()->item_name);
        $this->assertSame('250.00', $item->fresh()->subtotal_amount);
        $this->assertSame('250.00', $slip->fresh()->grand_total_amount);

        $this->put(route('payment-slips.editor.update', $slip), [
            'form_complete' => '1',
            'currency' => PaymentSlip::CURRENCY_USD,
            'transaction_description' => 'After edit',
            'supplier_id' => $supplier->id,
            'submit_after_save' => true,
            'invoices' => [[
                'id' => $invoice->id,
                'invoice_number' => 'NOTA-NEW',
                'invoice_date' => today()->toDateString(),
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'items' => [[
                    'id' => $item->id,
                    'item_name' => 'Updated item',
                    'quantity' => 2,
                    'unit_price_amount' => 125,
                ]],
            ]],
        ])
            ->assertRedirect(PaymentSlipResource::getUrl('view', ['record' => $slip]))
            ->assertSessionHasNoErrors();

        $this->assertSame('submitted', $slip->fresh()->status);
        $this->assertNotNull($slip->fresh()->submitted_at);
    }

    /** @return list<string> */
    private function fieldNames(string $page, array $params = []): array
    {
        $schema = Livewire::test($page, $params)->instance()->getSchema('form');

        return array_values(array_filter(array_map(
            static fn ($component): ?string => method_exists($component, 'getName') ? $component->getName() : null,
            $schema->getFlatComponents(withHidden: true),
        )));
    }
}
