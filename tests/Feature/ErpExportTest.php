<?php

namespace Tests\Feature;

use App\Actions\ExportPaymentSlipToErp;
use App\Actions\VerifyPaymentSlip;
use App\Filament\Pages\ErpExports;
use App\Filament\Resources\PaymentSlips\Pages\CreateExportPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\CreateGeneralPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\CreateImportPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\Buyer;
use App\Models\ChartOfAccount;
use App\Models\Division;
use App\Models\ErpExportBatch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentSlip;
use App\Models\PaymentSlipAudit;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\User;
use App\Services\Erp\AccountResolver;
use App\Services\Erp\ErpJournalBuilder;
use App\Services\Erp\ErpWorkbookWriter;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class ErpExportTest extends TestCase
{
    use RefreshDatabase;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['maker', 'checker', 'approver'] as $role) {
            Role::create(['name' => $role]);
        }
        $this->checker = User::factory()->create()->assignRole('checker');
        $this->actingAs($this->checker);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_builder_rounds_stored_taxes_and_preserves_separate_items_and_identifiers(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $rows = app(ErpJournalBuilder::class)->build($slip, [$slip->invoices()->first()->id => '000099']);
        $this->assertCount(10, $rows);
        $this->assertSame('I05_902020', $rows[0]->costCenter);
        $this->assertSame($rows[0]->account, $rows[1]->account);
        $this->assertSame(3300, $rows[2]->debit);
        $this->assertSame('11990501', $rows[2]->account);
        $this->assertSame(600, $rows[3]->credit);
        $this->assertSame('21020401', $rows[3]->account);
        $this->assertSame(32700, $rows[4]->credit);
        $this->assertSame('000123', $rows[4]->account);
        $this->assertSame('000099', $rows[4]->vatInvoiceNumber);
        $this->assertNull($rows[9]->vatInvoiceNumber);
        $this->assertSame('Handling 1 for Fixture Buyer inv 000045-Fixture Supplier', $rows[0]->description);
        $this->assertSame('000099 -Fixture Buyer inv 000045-Fixture Supplier', $rows[2]->description);
        $this->assertSame('PPh 23 export charge for Fixture Buyer inv 000045-Fixture Supplier', $rows[3]->description);
        $this->assertSame('AP export charge for Fixture Buyer inv 000045-Fixture Supplier', $rows[4]->description);
        $this->assertSame('Fixture Buyer inv 000046-Fixture Supplier', $rows[7]->description);
    }

    public function test_import_uses_item_level_taxes_and_creates_one_supplier_row_per_invoice(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => 'import']);
        $firstInvoice = $slip->invoices()->orderBy('id')->firstOrFail();
        $invoiceBuyer = Buyer::create(['code' => 'CARTER', 'name' => 'Carter', 'is_active' => true]);
        $firstInvoice->update([
            'buyer_id' => $invoiceBuyer->id,
            'vat_invoice_number' => '04002600305357263',
        ]);
        $items = $firstInvoice->items()->orderBy('line_number')->get();
        $items[0]->update([
            'source_supplier_name' => 'PT Pelindo Indonesia',
            'vat_invoice_number' => '0400042606998436',
            'tax_addition_amount' => '11.00',
            'tax_deduction_amount' => '6.00',
        ]);
        $items[1]->update([
            'source_supplier_name' => 'PT Interlink Indonesia',
            'vat_invoice_number' => '0400042606998437',
            'tax_addition_amount' => '22.00',
        ]);
        $slip->invoices()->orderBy('id')->skip(1)->firstOrFail()->items()->orderBy('line_number')->firstOrFail()->update([
            'tax_addition_amount' => '33.00',
            'tax_deduction_amount' => '6.00',
        ]);
        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());
        $firstInvoiceRows = collect($rows)->where('invoice', $firstInvoice->invoice_number);

        $this->assertSame('I05_190000', $rows[0]->costCenter);
        $this->assertCount(6, $firstInvoiceRows);
        $this->assertCount(2, $firstInvoiceRows->where('rowType', 'PPN'));
        $this->assertCount(1, $firstInvoiceRows->where('rowType', 'PPh'));
        $this->assertCount(1, $firstInvoiceRows->where('rowType', 'Supplier'));
        $this->assertSame(['Expense', 'PPN', 'PPh', 'Expense', 'PPN', 'Supplier'], $firstInvoiceRows->pluck('rowType')->values()->all());
        $this->assertSame('0400042606998436 -Carter inv 000045-PT Pelindo Indonesia', $firstInvoiceRows->where('rowType', 'PPN')->first()->description);
        $this->assertNull($firstInvoiceRows->where('rowType', 'PPN')->first()->vatInvoiceNumber);
        $this->assertSame('PPh 23 import charge for Carter inv 000045-PT Pelindo Indonesia', $firstInvoiceRows->where('rowType', 'PPh')->first()->description);
        $this->assertSame('AP import charge for Carter inv 000045-Fixture Supplier | Handling 1: PT Pelindo Indonesia; Handling 2: PT Interlink Indonesia', $firstInvoiceRows->where('rowType', 'Supplier')->first()->description);
        $this->assertSame('04002600305357263', $firstInvoiceRows->where('rowType', 'Supplier')->first()->vatInvoiceNumber);
        $this->assertSame('33.00', $firstInvoice->fresh()->tax_addition_amount);
        $this->assertSame('6.00', $firstInvoice->fresh()->tax_deduction_amount);
        $this->assertSame('327.00', $firstInvoice->fresh()->grand_total_amount);

        $path = Storage::disk('local')->path('import-item-taxes.xlsx');
        app(ErpWorkbookWriter::class)->write($rows, $path);
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $this->assertSame(['LedgerJournalTrans', 'Costcenter', 'Sub'], $book->getSheetNames());
        $this->assertNull($sheet->getCell('AV4')->getValue());
        $this->assertSame('0400042606998436 -Carter inv 000045-PT Pelindo Indonesia', $sheet->getCell('O4')->getValue());
        $this->assertSame('000123', $sheet->getCell('D8')->getValue());
        $this->assertSame('AP import charge for Carter inv 000045-Fixture Supplier | Handling 1: PT Pelindo Indonesia; Handling 2: PT Interlink Indonesia', $sheet->getCell('O8')->getValue());
        $this->assertSame('04002600305357263', $sheet->getCell('AV8')->getValue());
        $this->assertNull($sheet->getCell('O14')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_import_can_mix_invoice_and_item_tax_modes_without_grouping_item_taxes(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT]);
        $ppn = Tax::create(['code' => 'PPN-IMPORT-11', 'name' => 'PPN Import 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $vat = Tax::create(['code' => 'VAT-IMPORT-1.1', 'name' => 'VAT Import 1.1%', 'rate' => 1.1, 'calculation_type' => 'addition', 'is_active' => true]);
        [$invoiceLevel, $itemLevel] = $slip->invoices()->orderBy('id')->get()->all();

        $invoiceLevel->update([
            'tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY,
            'ppn_tax_id' => $ppn->id,
            'vat_invoice_number' => 'MAIN-VAT',
        ]);
        $itemLevel->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $itemLevel->items()->orderBy('id')->firstOrFail()->update([
            'ppn_tax_id' => $vat->id,
            'vat_invoice_number' => 'ITEM-VAT',
        ]);

        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());
        $this->assertSame(['Expense', 'Expense', 'PPN', 'Supplier', 'Expense', 'PPN', 'Expense', 'Supplier'], array_column($rows, 'rowType'));
        $this->assertSame(3300, $rows[2]->debit);
        $this->assertSame('MAIN-VAT -Fixture Buyer inv 000045-Fixture Supplier', $rows[2]->description);
        $this->assertSame(100, $rows[5]->debit);
        $this->assertSame('ITEM-VAT -Fixture Buyer inv 000046-Fixture Supplier', $rows[5]->description);
    }

    public function test_switching_to_invoice_tax_clears_existing_item_tax_values(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $item = $invoice->items()->firstOrFail();
        $item->update(['tax_addition_amount' => 11, 'tax_deduction_amount' => 2]);
        $ppn = Tax::create(['code' => 'PPN-MODE-SWITCH', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);

        $invoice->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY, 'ppn_tax_id' => $ppn->id]);

        $this->assertSame('0.00', $item->fresh()->tax_addition_amount);
        $this->assertSame('0.00', $item->fresh()->tax_deduction_amount);
        $this->assertSame($item->subtotal_amount, $item->fresh()->net_amount);
        $this->assertSame('33.00', $invoice->fresh()->tax_addition_amount);
    }

    public function test_import_invoice_tax_mode_rejects_supporting_supplier_detail(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY]);
        $invoice->items()->firstOrFail()->update(['source_supplier_name' => 'Third-party supplier']);

        $this->expectException(ValidationException::class);
        app(ErpJournalBuilder::class)->build($slip->fresh());
    }

    public function test_import_pdf_shows_invoice_summary_without_reimbursement_item_details(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update([
            'transaction_type' => 'import',
            'invoice_receipt_number' => 'HIJ / IMP / 001 / VIII / 2026',
        ]);
        $item = $slip->invoices()->firstOrFail()->items()->firstOrFail();
        $item->update(['item_name' => 'UNIQUE REIMBURSEMENT DETAIL']);
        $invoiceBuyer = Buyer::create(['code' => 'PDF-CARTER', 'name' => 'PDF Carter', 'is_active' => true]);
        $slip->invoices()->firstOrFail()->update(['buyer_id' => $invoiceBuyer->id]);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('#4F758B', $html);
        $this->assertStringContainsString('#EDF1F3', $html);
        $this->assertStringContainsString('REF 000045 - PDF Carter', $html);
        $this->assertStringContainsString('Tanda Terima Invoice', $html);
        $this->assertStringContainsString('HIJ / IMP / 001 / VIII / 2026', $html);
        $this->assertStringNotContainsString('UNIQUE REIMBURSEMENT DETAIL', $html);
    }

    public function test_export_pdf_shows_invoice_receipt_number_when_present(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['invoice_receipt_number' => 'HIJ / EXP / 001 / IX / 2026']);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('Tanda Terima Invoice', $html);
        $this->assertStringContainsString('HIJ / EXP / 001 / IX / 2026', $html);
    }

    public function test_general_pdf_shows_each_item_with_quantity_unit_price_and_subtotal(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL, 'buyer_id' => null]);
        $invoice = $slip->invoices()->firstOrFail();
        $invoice->items()->delete();
        $invoice->items()->create([
            'item_name' => 'Kertas A4',
            'quantity' => 3,
            'unit_price_amount' => 53210,
        ]);
        $invoice->items()->create([
            'item_name' => 'Jasa Fotokopi',
            'quantity' => 4,
            'unit_price_amount' => 1250,
        ]);
        $slip->load(['supplier', 'buyer', 'invoices.buyer', 'invoices.items', 'invoices.ppnTax', 'invoices.pphTax', 'creator.division']);

        $html = view('pdf.payment-slip', ['slip' => $slip])->render();

        $this->assertStringContainsString('1. Kertas A4', $html);
        $this->assertStringContainsString('Qty: 3', $html);
        $this->assertStringContainsString('Rp 53.210', $html);
        $this->assertStringContainsString('Rp 159.630', $html);
        $this->assertStringContainsString('2. Jasa Fotokopi', $html);
        $this->assertStringContainsString('Qty: 4', $html);
        $this->assertStringContainsString('Rp 1.250', $html);
        $this->assertStringContainsString('Rp 5.000', $html);
        $this->assertStringNotContainsString('Tanda Terima Invoice', $html);
    }

    public function test_itemized_fields_are_shared_by_import_and_new_export(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $ppn = Tax::create(['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        $page = Livewire::test(CreateImportPaymentSlip::class)
            ->fillForm([
                'transaction_type' => 'import',
                'invoices' => [[
                    'invoice_number' => 'FORM-001',
                    'invoice_date' => '2026-09-15',
                    'items' => [[
                        'item_name' => 'Form item',
                        'quantity' => 1,
                        'unit_price_amount' => 100,
                    ]],
                ]],
            ]);

        $page->assertSee('Nomor Tanda Terima Invoice')
            ->assertSee('Supplier Pendukung')
            ->assertSee('Pilih Buyer untuk invoice ini')
            ->assertSee('PPN 11%')
            ->assertSee('PPh 2%')
            ->assertSee('PPN Nominal')
            ->assertSee('PPh Nominal');

        $this->assertMatchesRegularExpression(
            '/wire:model\.live\.blur="data\.invoices\.[^"]+\.items\.[^"]+\.source_supplier_name"/',
            $page->html(),
        );

        $state = $page->get('data');
        $invoiceKey = array_key_first($state['invoices']);
        $itemKey = array_key_first($state['invoices'][$invoiceKey]['items']);
        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.quantity", 2)
            ->set("data.invoices.{$invoiceKey}.items.{$itemKey}.ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$invoiceKey}.items.{$itemKey}.pph_tax_id", $pph->id);
        $state = $page->get('data');
        $this->assertEquals(218, $this->formMoney($state['invoices'][$invoiceKey]['items'][$itemKey]['net_amount']));
        $this->assertEquals(200, $this->formMoney($state['invoices'][$invoiceKey]['subtotal_amount']));
        $this->assertEquals(218, $this->formMoney($state['invoices'][$invoiceKey]['grand_total_amount']));
        $page->assertSee('Rp 22')
            ->assertSee('Rp 4')
            ->assertSee('Rp 218');

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.quantity", 3);
        $state = $page->get('data');
        $this->assertEquals(33, $this->formMoney($state['invoices'][$invoiceKey]['items'][$itemKey]['tax_addition_amount']));
        $this->assertEquals(6, $this->formMoney($state['invoices'][$invoiceKey]['items'][$itemKey]['tax_deduction_amount']));
        $this->assertEquals(327, $this->formMoney($state['invoices'][$invoiceKey]['grand_total_amount']));
        $page->assertSee('Rp 33')->assertSee('Rp 327');

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.ppn_tax_id", null);
        $state = $page->get('data');
        $this->assertEquals(0, $this->formMoney($state['invoices'][$invoiceKey]['items'][$itemKey]['tax_addition_amount']));
        $this->assertEquals(294, $this->formMoney($state['invoices'][$invoiceKey]['grand_total_amount']));
        $page->assertSee('Rp 294');

        $exportPage = Livewire::test(CreateExportPaymentSlip::class)
            ->fillForm(['invoices' => [[
                'invoice_number' => 'EXP-FORM-001',
                'invoice_date' => '2026-09-16',
                'items' => [[
                    'item_name' => 'Handling',
                    'quantity' => 1,
                    'unit_price_amount' => 100,
                ]],
            ]]])
            ->assertSee('Nomor Tanda Terima Invoice')
            ->assertSee('Pilih Buyer untuk invoice ini')
            ->assertDontSee('Supplier Pendukung')
            ->assertSee('PPN Nominal')
            ->assertSee('PPh Nominal');

        $exportState = $exportPage->get('data');
        $exportInvoiceKey = array_key_first($exportState['invoices']);
        $exportItemKey = array_key_first($exportState['invoices'][$exportInvoiceKey]['items']);
        $exportPage->set("data.invoices.{$exportInvoiceKey}.items.{$exportItemKey}.ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$exportInvoiceKey}.items.{$exportItemKey}.pph_tax_id", $pph->id)
            ->assertSee('Rp 11')
            ->assertSee('Rp 2')
            ->assertSee('Rp 109');
    }

    public function test_browser_tax_selection_recalculates_even_when_schema_update_hooks_do_not_run(): void
    {
        $this->actingAs($this->makerForDivision('EXIM'));
        $ppn = Tax::create(['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $vat = Tax::create(['code' => 'VAT-1.1', 'name' => 'VAT 1.1%', 'rate' => 1.1, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        $page = Livewire::test(CreateExportPaymentSlip::class)->fillForm([
            'invoices' => [
                ['invoice_number' => 'EXP-11', 'invoice_date' => '2026-09-17', 'items' => [
                    ['item_name' => 'Handling', 'quantity' => 1, 'unit_price_amount' => 185000],
                    ['item_name' => 'Documentation', 'quantity' => 1, 'unit_price_amount' => 400000],
                ]],
                ['invoice_number' => 'EXP-1.1', 'invoice_date' => '2026-09-17', 'items' => [['item_name' => 'Freight', 'quantity' => 1, 'unit_price_amount' => 100000]]],
            ],
        ]);
        $page->call('disableSchemaStateUpdateHooksForTesting');
        $state = $page->get('data');
        [$firstKey, $secondKey] = array_keys($state['invoices']);
        $firstItemKey = array_key_first($state['invoices'][$firstKey]['items']);
        $secondItemInFirstInvoiceKey = array_keys($state['invoices'][$firstKey]['items'])[1];
        $secondItemKey = array_key_first($state['invoices'][$secondKey]['items']);

        $page->set("data.invoices.{$firstKey}.items.{$firstItemKey}.ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$firstKey}.items.{$firstItemKey}.pph_tax_id", $pph->id)
            ->set("data.invoices.{$firstKey}.items.{$secondItemInFirstInvoiceKey}.ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$secondKey}.items.{$secondItemKey}.ppn_tax_id", $vat->id);
        $state = $page->get('data');

        $this->assertEquals(20350, $this->formMoney($state['invoices'][$firstKey]['items'][$firstItemKey]['tax_addition_amount']));
        $this->assertEquals(3700, $this->formMoney($state['invoices'][$firstKey]['items'][$firstItemKey]['tax_deduction_amount']));
        $this->assertEquals(44000, $this->formMoney($state['invoices'][$firstKey]['items'][$secondItemInFirstInvoiceKey]['tax_addition_amount']));
        $this->assertEquals(645650, $this->formMoney($state['invoices'][$firstKey]['grand_total_amount']));
        $this->assertEquals(1100, $this->formMoney($state['invoices'][$secondKey]['tax_addition_amount']));
        $this->assertEquals(101100, $this->formMoney($state['invoices'][$secondKey]['grand_total_amount']));

        $page->set("data.invoices.{$firstKey}.items.{$firstItemKey}.pph_tax_id", null);
        $state = $page->get('data');
        $this->assertNull($state['invoices'][$firstKey]['items'][$firstItemKey]['pph_tax_id']);
        $this->assertEquals(0, $this->formMoney($state['invoices'][$firstKey]['tax_deduction_amount']));
        $this->assertEquals(649350, $this->formMoney($state['invoices'][$firstKey]['grand_total_amount']));

        $importPage = Livewire::test(CreateImportPaymentSlip::class)->fillForm([
            'invoices' => [['invoice_number' => 'IMP-11', 'invoice_date' => '2026-09-17', 'items' => [['item_name' => 'Customs', 'quantity' => 1, 'unit_price_amount' => 400000]]]],
        ]);
        $importPage->call('disableSchemaStateUpdateHooksForTesting');
        $state = $importPage->get('data');
        $invoiceKey = array_key_first($state['invoices']);
        $itemKey = array_key_first($state['invoices'][$invoiceKey]['items']);
        $importPage->set("data.invoices.{$invoiceKey}.items.{$itemKey}.ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$invoiceKey}.items.{$itemKey}.pph_tax_id", $pph->id);
        $this->assertEquals(436000, $this->formMoney($importPage->get('data')['invoices'][$invoiceKey]['grand_total_amount']));
    }

    public function test_invoice_tax_shortcut_changes_only_selected_invoice_without_taxing_each_item(): void
    {
        $this->actingAs($this->makerForDivision('EXIM'));
        $ppn = Tax::create(['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $vat = Tax::create(['code' => 'VAT-1.1', 'name' => 'VAT 1.1%', 'rate' => 1.1, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);
        $supplier = Supplier::create(['code' => 'BULK-SUP', 'name' => 'Bulk Supplier', 'is_active' => true]);
        $buyer = Buyer::create(['code' => 'BULK-BUY', 'name' => 'Bulk Buyer', 'is_active' => true]);

        $page = Livewire::test(CreateExportPaymentSlip::class)->fillForm([
            'supplier_id' => $supplier->id,
            'invoices' => [
                ['buyer_id' => $buyer->id, 'invoice_number' => 'EXP-BULK-11', 'invoice_date' => '2026-09-17', 'items' => [
                    ['item_name' => 'A', 'quantity' => 1, 'unit_price_amount' => 100],
                    ['item_name' => 'B', 'quantity' => 1, 'unit_price_amount' => 200],
                ]],
                ['buyer_id' => $buyer->id, 'invoice_number' => 'EXP-BULK-1.1', 'invoice_date' => '2026-09-17', 'items' => [
                    ['item_name' => 'C', 'quantity' => 1, 'unit_price_amount' => 100],
                ]],
            ],
        ])->assertSee('Terapkan PPN')->assertSee('Terapkan PPh');

        $state = $page->get('data');
        [$firstInvoiceKey, $secondInvoiceKey] = array_keys($state['invoices']);
        $page->set("data.invoices.{$firstInvoiceKey}.bulk_ppn_tax_id", $ppn->id)
            ->set("data.invoices.{$firstInvoiceKey}.bulk_pph_tax_id", $pph->id);
        $this->assertNull(array_values($page->get('data')['invoices'][$firstInvoiceKey]['items'])[0]['ppn_tax_id'] ?? null);

        $page->callAction(TestAction::make('apply_ppn_to_items')->schemaComponent("invoices.{$firstInvoiceKey}.bulk_ppn_actions"));
        $state = $page->get('data');
        foreach ($state['invoices'][$firstInvoiceKey]['items'] as $item) {
            $this->assertNull($item['ppn_tax_id'] ?? null);
            $this->assertNull($item['pph_tax_id'] ?? null);
        }
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $state['invoices'][$firstInvoiceKey]['tax_calculation_mode']);
        $this->assertEquals($ppn->id, $state['invoices'][$firstInvoiceKey]['ppn_tax_id']);
        $this->assertEquals($pph->id, $state['invoices'][$firstInvoiceKey]['pph_tax_id']);
        $this->assertNotSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $state['invoices'][$secondInvoiceKey]['tax_calculation_mode'] ?? null, 'Shortcut must not switch the other invoice.');
        $this->assertNull($state['invoices'][$secondInvoiceKey]['ppn_tax_id'] ?? null, 'Shortcut must not set tax on the other invoice.');
        $this->assertNull(array_values($state['invoices'][$secondInvoiceKey]['items'])[0]['ppn_tax_id'] ?? null);
        $this->assertEquals(327, $this->formMoney($page->get('data')['invoices'][$firstInvoiceKey]['grand_total_amount']));

        $secondItemKey = array_key_first($state['invoices'][$secondInvoiceKey]['items']);
        $page->set("data.invoices.{$secondInvoiceKey}.items.{$secondItemKey}.ppn_tax_id", $vat->id);
        $this->assertEquals(101, $this->formMoney($page->get('data')['invoices'][$secondInvoiceKey]['grand_total_amount']));

        $page->set("data.invoices.{$firstInvoiceKey}.pph_tax_id", null);
        $state = $page->get('data');
        $this->assertEquals(0, $this->formMoney($state['invoices'][$firstInvoiceKey]['tax_deduction_amount']));
        $this->assertEquals(333, $this->formMoney($state['invoices'][$firstInvoiceKey]['grand_total_amount']));

        $page->call('create')->assertHasNoFormErrors();
        $slip = PaymentSlip::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame(PaymentSlip::TAX_MODE_ITEMIZED, $slip->tax_calculation_mode, 'Parent slip must remain itemized.');
        $this->assertSame('434.00', $slip->grand_total_amount);
        $this->assertCount(2, $slip->invoices);
        $this->assertSame('0.00', $slip->invoices()->where('invoice_number', 'EXP-BULK-11')->firstOrFail()->tax_deduction_amount);
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $slip->invoices()->where('invoice_number', 'EXP-BULK-11')->firstOrFail()->tax_calculation_mode);
        $this->assertSame(PaymentSlip::TAX_MODE_ITEMIZED, $slip->invoices()->where('invoice_number', 'EXP-BULK-1.1')->firstOrFail()->tax_calculation_mode, 'Second invoice must remain itemized.');

        $coa = ChartOfAccount::create(['code' => '43011011', 'name' => 'Handling', 'is_active' => true]);
        InvoiceItem::query()->whereIn('invoice_id', $slip->invoices()->pluck('id'))
            ->update(['coa_id' => $coa->id, 'coa_code_snapshot' => $coa->code, 'coa_name_snapshot' => $coa->name]);
        $slip->update(['status' => 'approved']);
        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());
        $this->assertSame(['Expense', 'Expense', 'PPN', 'Supplier', 'Expense', 'PPN', 'Supplier'], array_column($rows, 'rowType'));
        $this->assertSame(3300, $rows[2]->debit);
        $this->assertSame(100, $rows[5]->debit);
    }

    public function test_create_payment_slip_rolls_back_parent_when_an_item_fails(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $slip = ErpPaymentSlip::create($this->checker);
        $supplierId = $slip->supplier_id;
        $buyerId = $slip->buyer_id;
        $existingCount = PaymentSlip::count();
        InvoiceItem::creating(fn () => throw new \RuntimeException('Simulated item failure'));

        try {
            Livewire::test(CreateImportPaymentSlip::class)
                ->fillForm([
                    'transaction_type' => 'import',
                    'supplier_id' => $supplierId,
                    'buyer_id' => $buyerId,
                    'invoices' => [[
                        'buyer_id' => $buyerId,
                        'invoice_number' => 'ROLLBACK-001',
                        'invoice_date' => '2026-09-15',
                        'subtotal_amount' => 100,
                        'items' => [[
                            'item_name' => 'Rollback item',
                            'quantity' => 1,
                            'unit_price_amount' => 100,
                            'tax_addition_amount' => 0,
                        ]],
                    ]],
                ])
                ->call('create');
            $this->fail('Expected item save failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated item failure', $exception->getMessage());
            $this->assertSame($existingCount, PaymentSlip::count());
            $this->assertDatabaseMissing('invoices', ['invoice_number' => 'ROLLBACK-001']);
        } finally {
            InvoiceItem::flushEventListeners();
        }
    }

    public function test_import_form_stores_buyer_on_each_invoice_without_a_payment_slip_buyer(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'FNS', 'name' => 'PT FNS Transbuana', 'is_active' => true]);
        $carter = Buyer::create(['code' => 'CARTER-FORM', 'name' => 'Carter', 'is_active' => true]);
        $ppn = Tax::create(['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        Livewire::test(CreateImportPaymentSlip::class)
            ->fillForm([
                'transaction_type' => 'import',
                'supplier_id' => $supplier->id,
                'invoice_receipt_number' => 'HIJ / IMP / 001 / VIII / 2026',
                'invoices' => [[
                    'buyer_id' => $carter->id,
                    'invoice_number' => '2B-2607-014',
                    'invoice_date' => '2026-07-01',
                    'items' => [[
                        'item_name' => 'Custom Clearance',
                        'quantity' => 1,
                        'unit_price_amount' => 400000,
                        'ppn_tax_id' => $ppn->id,
                        'pph_tax_id' => $pph->id,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::query()->where('invoice_number', '2B-2607-014')->firstOrFail();
        $this->assertSame($carter->id, $invoice->buyer_id);
        $this->assertNull($invoice->paymentSlip->buyer_id);
        $this->assertSame('HIJ / IMP / 001 / VIII / 2026', $invoice->paymentSlip->invoice_receipt_number);
        $this->assertSame('436000.00', $invoice->grand_total_amount);
    }

    public function test_new_export_uses_item_taxes_and_different_buyers_in_one_slip(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'EXP-SUP', 'name' => 'Export Supplier', 'is_active' => true]);
        $firstBuyer = Buyer::create(['code' => 'EXP-A', 'name' => 'Walmart', 'is_active' => true]);
        $secondBuyer = Buyer::create(['code' => 'EXP-B', 'name' => 'Carter', 'is_active' => true]);
        $ppn = Tax::create(['code' => 'PPN-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $ppnHigher = Tax::create(['code' => 'PPN-22', 'name' => 'PPN 22%', 'rate' => 22, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        Livewire::test(CreateExportPaymentSlip::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'invoice_receipt_number' => 'HIJ / EXP / 001 / IX / 2026',
                'invoices' => [
                    [
                        'buyer_id' => $firstBuyer->id,
                        'invoice_number' => 'EXP-ITEM-001',
                        'invoice_date' => '2026-09-17',
                        'vat_invoice_number' => 'VAT-EXP-MAIN',
                        'items' => [
                            ['item_name' => 'Taxable handling', 'quantity' => 1, 'unit_price_amount' => 100, 'ppn_tax_id' => $ppn->id, 'pph_tax_id' => $pph->id, 'vat_invoice_number' => 'VAT-EXP-ITEM-1'],
                            ['item_name' => 'Untaxed handling', 'quantity' => 1, 'unit_price_amount' => 50],
                        ],
                    ],
                    [
                        'buyer_id' => $secondBuyer->id,
                        'invoice_number' => 'EXP-ITEM-002',
                        'invoice_date' => '2026-09-17',
                        'items' => [
                            ['item_name' => 'Service', 'quantity' => 1, 'unit_price_amount' => 200, 'pph_tax_id' => $pph->id],
                        ],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertSame(PaymentSlip::TAX_MODE_ITEMIZED, $slip->tax_calculation_mode);
        $this->assertSame('HIJ / EXP / 001 / IX / 2026', $slip->invoice_receipt_number);
        $this->assertNull($slip->buyer_id);
        $this->assertSame('355.00', $slip->grand_total_amount);
        $first = $slip->invoices()->where('invoice_number', 'EXP-ITEM-001')->firstOrFail();
        $second = $slip->invoices()->where('invoice_number', 'EXP-ITEM-002')->firstOrFail();
        $this->assertSame($firstBuyer->id, $first->buyer_id);
        $this->assertSame($secondBuyer->id, $second->buyer_id);
        $this->assertSame('159.00', $first->grand_total_amount);
        $this->assertSame('196.00', $second->grand_total_amount);

        $taxedItem = $first->items()->orderBy('line_number')->firstOrFail();
        $this->assertSame($ppn->id, $taxedItem->ppn_tax_id);
        $this->assertSame($pph->id, $taxedItem->pph_tax_id);
        $taxedItem->update(['tax_addition_amount' => 999]);
        $this->assertSame('11.00', $taxedItem->fresh()->tax_addition_amount);

        $editPage = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $editState = $editPage->get('data');
        $invoiceKey = array_key_first($editState['invoices']);
        $itemKey = array_key_first($editState['invoices'][$invoiceKey]['items']);
        $editPage->set("data.invoices.{$invoiceKey}.items.{$itemKey}.ppn_tax_id", $ppnHigher->id)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('170.00', $first->fresh()->grand_total_amount);
        $this->assertSame('366.00', $slip->fresh()->grand_total_amount);

        $coa = ChartOfAccount::create(['code' => '43011011', 'name' => 'Handling', 'is_active' => true]);
        foreach ($slip->invoices as $invoice) {
            $invoice->items()->update([
                'coa_id' => $coa->id,
                'coa_code_snapshot' => $coa->code,
                'coa_name_snapshot' => $coa->name,
            ]);
        }
        $slip->update(['status' => 'approved']);
        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());
        $this->assertSame(['Expense', 'PPN', 'PPh', 'Expense', 'Supplier', 'Expense', 'PPh', 'Supplier'], array_column($rows, 'rowType'));
        $this->assertSame('VAT-EXP-ITEM-1 -Walmart inv EXP-ITEM-001-Export Supplier', $rows[1]->description);
        $this->assertSame('AP export charge for Walmart inv EXP-ITEM-001-Export Supplier', $rows[4]->description);
        $this->assertSame('VAT-EXP-MAIN', $rows[4]->vatInvoiceNumber);
        $this->assertSame('AP export charge for Carter inv EXP-ITEM-002-Export Supplier', $rows[7]->description);
        $this->assertSame('I05_902020', $rows[0]->costCenter);
    }

    public function test_existing_export_keeps_invoice_tax_mode_on_edit(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);

        Livewire::test(EditPaymentSlip::class, ['record' => $slip->id])
            ->assertSee('PPN (Penambahan)')
            ->assertDontSee('PPN Nominal')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $slip->fresh()->tax_calculation_mode);
        $this->assertSame('33.01', $slip->invoices()->orderBy('id')->firstOrFail()->tax_addition_amount);

        $slip->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $slip->fresh()->tax_calculation_mode);
    }

    public function test_edit_page_renders_newly_extracted_invoice_without_a_saved_record(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'draft']);
        $ppn = Tax::create(['code' => 'PPN-NEW-11', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);
        $pph = Tax::create(['code' => 'PPH-NEW-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $invoices = $page->get('data')['invoices'];
        $newInvoice = reset($invoices);
        $newInvoice['invoice_number'] = 'EXTRACTED-NEW';
        $newInvoice['tax_calculation_mode'] = PaymentSlip::TAX_MODE_ITEMIZED;
        $invoices['new-extracted-invoice'] = $newInvoice;

        $page->set('data.tax_calculation_mode', PaymentSlip::TAX_MODE_ITEMIZED)
            ->set('data.invoices', $invoices)
            ->assertHasNoErrors();
        $this->assertSame('EXTRACTED-NEW', $page->get('data')['invoices']['new-extracted-invoice']['invoice_number']);

        $page->set('data.invoices.new-extracted-invoice.bulk_ppn_tax_id', $ppn->id)
            ->set('data.invoices.new-extracted-invoice.bulk_pph_tax_id', $pph->id)
            ->callAction(TestAction::make('apply_ppn_to_items')->schemaComponent('invoices.new-extracted-invoice.bulk_ppn_actions'))
            ->assertHasNoErrors();
        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $page->get('data')['invoices']['new-extracted-invoice']['tax_calculation_mode']);
        $this->assertEquals($ppn->id, $page->get('data')['invoices']['new-extracted-invoice']['ppn_tax_id']);
        $this->assertEquals($pph->id, $page->get('data')['invoices']['new-extracted-invoice']['pph_tax_id']);
    }

    public function test_edit_page_can_apply_pph_first_to_newly_extracted_invoice(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'draft']);
        $pph = Tax::create(['code' => 'PPH-NEW-2', 'name' => 'PPh 2%', 'rate' => 2, 'calculation_type' => 'deduction', 'is_active' => true]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $invoices = $page->get('data')['invoices'];
        $newInvoice = reset($invoices);
        $newInvoice['invoice_number'] = 'EXTRACTED-PPH';
        $newInvoice['tax_calculation_mode'] = PaymentSlip::TAX_MODE_ITEMIZED;
        $invoices['new-extracted-invoice'] = $newInvoice;

        $page->set('data.tax_calculation_mode', PaymentSlip::TAX_MODE_ITEMIZED)
            ->set('data.invoices', $invoices)
            ->set('data.invoices.new-extracted-invoice.bulk_pph_tax_id', $pph->id)
            ->callAction(TestAction::make('apply_pph_to_items')->schemaComponent('invoices.new-extracted-invoice.bulk_pph_actions'))
            ->assertHasNoErrors();

        $this->assertSame(PaymentSlip::TAX_MODE_INVOICE_LEGACY, $page->get('data')['invoices']['new-extracted-invoice']['tax_calculation_mode']);
        $this->assertEquals($pph->id, $page->get('data')['invoices']['new-extracted-invoice']['pph_tax_id']);
    }

    public function test_existing_import_manual_item_tax_survives_checker_edit_without_tax_selection(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT, 'status' => 'submitted']);
        $slip->invoices()->update(['buyer_id' => $slip->buyer_id]);
        $item = $slip->invoices()->firstOrFail()->items()->firstOrFail();
        $item->update(['tax_addition_amount' => 11, 'tax_deduction_amount' => 2]);

        Livewire::test(EditPaymentSlip::class, ['record' => $slip->id])
            ->assertSee('Rp 11')
            ->assertSee('Rp 2')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($item->fresh()->ppn_tax_id);
        $this->assertNull($item->fresh()->pph_tax_id);
        $this->assertSame('11.00', $item->fresh()->tax_addition_amount);
        $this->assertSame('2.00', $item->fresh()->tax_deduction_amount);
    }

    public function test_item_rejects_a_ppn_master_record_as_pph(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $item = $slip->invoices()->firstOrFail()->items()->firstOrFail();
        $ppn = Tax::create(['code' => 'ONLY-PPN', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);

        $this->expectException(ValidationException::class);
        $item->update(['pph_tax_id' => $ppn->id]);
    }

    public function test_export_and_import_are_restricted_to_exim_while_general_is_available_to_all_makers(): void
    {
        $eximMaker = $this->makerForDivision('EXIM');
        $this->actingAs($eximMaker)
            ->get(PaymentSlipResource::getUrl('create-export'))
            ->assertOk();
        $this->get(PaymentSlipResource::getUrl('create-import'))->assertOk();
        $this->get(PaymentSlipResource::getUrl('create-general'))->assertOk();

        $generalMaker = $this->makerForDivision('HR');
        $this->actingAs($generalMaker)
            ->get(PaymentSlipResource::getUrl('create-general'))
            ->assertOk();
        $this->get(PaymentSlipResource::getUrl('create-export'))->assertForbidden();
        $this->get(PaymentSlipResource::getUrl('create-import'))->assertForbidden();
    }

    public function test_exim_payment_navigation_offers_one_shared_exim_form(): void
    {
        $this->actingAs($this->makerForDivision('EXIM'));

        $labels = collect(PaymentSlipResource::getNavigationItems())
            ->sortBy(fn ($item): int => $item->getSort())
            ->map(fn ($item): string => $item->getLabel())
            ->values()
            ->all();

        $this->assertSame([
            'Payment Slips',
            'New General Payment Slip',
            'New EXIM Payment Slip',
        ], $labels);
    }

    public function test_accounting_user_with_maker_and_checker_roles_can_create_and_check_payment_slips(): void
    {
        $accountingUser = $this->makerForDivision('ACC');
        $accountingUser->assignRole('checker');

        $this->actingAs($accountingUser)
            ->get(PaymentSlipResource::getUrl('create-general'))
            ->assertOk();

        $this->assertTrue(PaymentSlipResource::canCreateType($accountingUser, PaymentSlip::TYPE_GENERAL));
        $this->assertTrue($accountingUser->hasRole('checker'));
    }

    public function test_export_and_general_forms_calculate_invoice_subtotal_from_items(): void
    {
        $eximMaker = $this->makerForDivision('EXIM');
        $this->actingAs($eximMaker);
        $exportPage = Livewire::test(CreateExportPaymentSlip::class)
            ->fillForm([
                'invoices' => [[
                    'invoice_number' => 'EXP-FORM-001',
                    'invoice_date' => '2026-09-16',
                    'items' => [[
                        'item_name' => 'Handling',
                        'quantity' => 1,
                        'unit_price_amount' => 125000,
                    ]],
                ]],
            ]);
        $exportState = $exportPage->get('data');
        $invoiceKey = array_key_first($exportState['invoices']);
        $itemKey = array_key_first($exportState['invoices'][$invoiceKey]['items']);
        $exportPage->set("data.invoices.{$invoiceKey}.items.{$itemKey}.quantity", 2);
        $exportState = $exportPage->get('data');
        $this->assertEquals(250000, $this->formMoney($exportState['invoices'][$invoiceKey]['subtotal_amount']));
        $this->assertEquals(250000, $this->formMoney($exportState['invoices'][$invoiceKey]['grand_total_amount']));

        $generalMaker = $this->makerForDivision('HR');
        $this->actingAs($generalMaker);
        $generalPage = Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'invoices' => [[
                    'invoice_number' => 'NOTA-001',
                    'invoice_date' => '2026-09-16',
                    'items' => [[
                        'item_name' => 'Kertas A4',
                        'quantity' => 2,
                        'unit_price_amount' => 50000,
                    ]],
                ]],
            ])
            ->assertDontSee('Pilih Buyer')
            ->assertDontSee('Supplier Pendukung');
        $generalState = $generalPage->get('data');
        $invoiceKey = array_key_first($generalState['invoices']);
        $itemKey = array_key_first($generalState['invoices'][$invoiceKey]['items']);
        $generalPage->set("data.invoices.{$invoiceKey}.items.{$itemKey}.quantity", 3);
        $generalState = $generalPage->get('data');
        $this->assertSame(PaymentSlip::TYPE_GENERAL, $generalState['transaction_type']);
        $this->assertEquals(150000, $this->formMoney($generalState['invoices'][$invoiceKey]['subtotal_amount']));
        $this->assertEquals(150000, $this->formMoney($generalState['invoices'][$invoiceKey]['grand_total_amount']));
    }

    public function test_general_payment_slip_uses_shared_erp_workbook_contract_without_buyer(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_GENERAL, 'buyer_id' => null]);
        $slip->invoices()->update(['buyer_id' => null]);
        $slip->refresh();

        $rows = app(ErpJournalBuilder::class)->build($slip);

        $this->assertSame('I05_190000', $rows[0]->costCenter);
        $this->assertStringContainsString('for inv 000045-Fixture Supplier', $rows[0]->description);
        $this->assertStringNotContainsString('Fixture Buyer', $rows[0]->description);
        $this->assertSame(collect($rows)->sum('debit'), collect($rows)->sum('credit'));

        $path = Storage::disk('local')->path('general-payment-slip.xlsx');
        app(ErpWorkbookWriter::class)->write($rows, $path);
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $this->assertSame(['LedgerJournalTrans', 'Costcenter', 'Sub'], $book->getSheetNames());
        $this->assertSame('BG', $sheet->getHighestColumn());
        $this->assertSame('000045', $sheet->getCell('AL5')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_general_page_creates_shared_payment_slip_invoice_and_items(): void
    {
        $maker = $this->makerForDivision('HR');
        $this->actingAs($maker);
        $supplier = Supplier::create(['code' => 'ATK-STORE', 'name' => 'Toko ATK', 'is_active' => true]);

        Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'supplier_id' => $supplier->id,
                'invoices' => [[
                    'invoice_number' => 'NOTA-ATK-001',
                    'invoice_date' => '2026-09-16',
                    'items' => [[
                        'item_name' => 'Kertas A4',
                        'quantity' => 2,
                        'unit_price_amount' => 50000,
                    ]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $slip = PaymentSlip::query()->where('created_by', $maker->id)->firstOrFail();
        $invoice = $slip->invoices()->with('items')->firstOrFail();
        $this->assertSame(PaymentSlip::TYPE_GENERAL, $slip->transaction_type);
        $this->assertNull($slip->buyer_id);
        $this->assertSame('100000.00', $invoice->subtotal_amount);
        $this->assertSame('100000.00', $invoice->grand_total_amount);
        $this->assertSame('Kertas A4', $invoice->items->first()->item_name);
    }

    private function formMoney(mixed $value): float
    {
        if (is_string($value) && str_contains($value, ',')) {
            return (float) str_replace(['.', ','], ['', '.'], $value);
        }

        if (is_string($value) && preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value)) {
            return (float) str_replace('.', '', $value);
        }

        return (float) $value;
    }

    private function makerForDivision(string $code): User
    {
        $division = Division::firstOrCreate(
            ['code' => $code],
            ['name' => $code.' Division', 'is_active' => true],
        );

        return User::factory()->create(['division_id' => $division->id])->assignRole('maker');
    }

    public function test_account_resolution_order(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        $item = $invoice->items()->first();
        $resolver = app(AccountResolver::class);
        $this->assertSame('43011011', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = null;
        $item->setRelation('chartOfAccount', new ChartOfAccount(['code' => '222']));
        $this->assertSame('222', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = '111';
        $this->assertSame('111', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = null;
        $item->setRelation('chartOfAccount', null);
        $this->expectException(ValidationException::class);
        $resolver->resolve($item, $invoice);
    }

    public function test_item_coa_snapshot_does_not_recalculate_stored_taxes(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $item = $slip->invoices()->first()->items()->first();
        $coa = ChartOfAccount::create(['code' => '222', 'name' => 'Other expense']);
        $item->update(['coa_id' => $coa->id]);
        $this->assertSame('222', $item->fresh()->coa_code_snapshot);
        $this->assertSame('Other expense', $item->fresh()->coa_name_snapshot);
        $this->assertSame('33.01', $item->invoice->fresh()->tax_addition_amount);
        $this->assertSame('6.01', $item->invoice->fresh()->tax_deduction_amount);
        app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
        $verified = $slip->fresh();
        $this->assertSame('approved', $verified->status);
        $this->assertSame($this->checker->id, $verified->approved_by);
        $this->assertNotNull($verified->verified_at);
        $this->assertNotNull($verified->approved_at);
        $this->expectException(AuthorizationException::class);
        $item->fresh()->update(['coa_id' => null]);
    }

    public function test_maker_cannot_change_item_coa(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $this->actingAs(User::factory()->create()->assignRole('maker'));
        $otherCoa = ChartOfAccount::create(['code' => '999', 'name' => 'Forbidden account']);
        $this->expectException(AuthorizationException::class);
        $slip->invoices()->first()->items()->first()->update(['coa_id' => $otherCoa->id]);
    }

    public function test_stale_item_model_cannot_change_coa_after_verification(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $item = $slip->invoices()->first()->items()->first();
        $item->load('invoice.paymentSlip');
        $slip->update(['status' => 'approved']);
        $otherCoa = ChartOfAccount::create(['code' => '998', 'name' => 'Late account']);
        $this->expectException(AuthorizationException::class);
        $item->update(['coa_id' => $otherCoa->id]);
    }

    public function test_export_and_download_are_atomic_and_preserve_workbook_contract(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker, [$slip->invoices()->first()->id => '000099']);
        $this->assertSame('exported', $slip->fresh()->status);
        $this->assertDatabaseCount('erp_export_batches', 1);
        $this->assertDatabaseCount('erp_export_items', 1);
        $this->assertDatabaseHas('payment_slip_audits', ['event' => 'erp_exported']);
        $this->assertSame('2026-09-04', $batch->approval_date_from->toDateString());
        $this->assertSame($batch->approval_date_from->toDateString(), $batch->approval_date_to->toDateString());
        $book = IOFactory::load(Storage::disk('local')->path($batch->file_path));
        $template = IOFactory::load(config('erp-export.template'));
        $this->assertSame(['LedgerJournalTrans', 'Costcenter', 'Sub'], $book->getSheetNames());
        $sheet = $book->getSheet(0);
        $this->assertSame('BG', $sheet->getHighestColumn());
        $this->assertSame($template->getSheet(0)->rangeToArray('A1:BG2', ''), $sheet->rangeToArray('A1:BG2', ''));
        foreach ([1, 2] as $index) {
            $this->assertSame($template->getSheet($index)->toArray(), $book->getSheet($index)->toArray());
        }
        $this->assertSame('000123', $sheet->getCell('D7')->getValue());
        $this->assertSame('000123', $sheet->getCell('I7')->getValue());
        $this->assertSame('000045', $sheet->getCell('AL7')->getValue());
        $this->assertSame('000099', $sheet->getCell('AV7')->getValue());
        $this->assertSame('AP_OTP', $sheet->getCell('T7')->getValue());
        $this->assertSame('i2', $sheet->getCell('U5')->getValue());
        $this->assertSame('Handling 1 for Fixture Buyer inv 000045-Fixture Supplier', $sheet->getCell('O3')->getValue());
        $this->assertSame('000099 -Fixture Buyer inv 000045-Fixture Supplier', $sheet->getCell('O5')->getValue());
        $this->assertSame('PPh 23 export charge for Fixture Buyer inv 000045-Fixture Supplier', $sheet->getCell('O6')->getValue());
        $this->assertSame('AP export charge for Fixture Buyer inv 000045-Fixture Supplier', $sheet->getCell('O7')->getValue());
        foreach (['D7', 'I7', 'AL7', 'AV7'] as $cell) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType());
        }
        foreach (['B3', 'AN7'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType());
            $this->assertSame('2026-08-31', Date::excelToDateTimeObject($sheet->getCell($cell)->getValue())->format('Y-m-d'));
        }
        $debit = $credit = 0;
        for ($row = 3; $row <= 12; $row++) {
            $this->assertSame(1, $sheet->getCell('A'.$row)->getValue());
            $debit += $sheet->getCell('P'.$row)->getValue() ?? 0;
            $credit += $sheet->getCell('Q'.$row)->getValue() ?? 0;
            $this->assertNull($sheet->getCell('BG'.$row)->getValue());
        }
        $this->assertEqualsWithDelta(666.00, $debit, 0.001);
        $this->assertEqualsWithDelta($debit, $credit, 0.001);
        $this->assertNull($sheet->getCell('O13')->getValue());
        $book->disconnectWorksheets();
        $template->disconnectWorksheets();
        $this->get(route('erp-exports.download', $batch))->assertOk()->assertDownload();
        $this->assertDatabaseCount('erp_export_batches', 1);
        $this->expectException(ValidationException::class);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
    }

    public function test_database_failure_removes_file_and_rolls_back_every_record(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        PaymentSlipAudit::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected export failure');
        } catch (ValidationException) {
            $this->assertSame('approved', $slip->fresh()->status);
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertDatabaseCount('erp_export_items', 0);
            $this->assertDatabaseCount('payment_slip_audits', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            PaymentSlipAudit::flushEventListeners();
        }
    }

    public function test_failed_writer_cleans_up_partial_file(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $this->mock(ErpWorkbookWriter::class)->shouldReceive('write')->once()->andReturnUsing(function ($rows, $path): void {
            file_put_contents($path, 'partial');
            throw new \RuntimeException('Disk failure');
        });
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected export failure');
        } catch (ValidationException) {
            $this->assertSame('approved', $slip->fresh()->status);
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_invalid_balance_and_pending_approval_block_export(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        foreach (['pending_approval', 'draft', 'submitted', 'exported'] as $status) {
            $slip->update(['status' => $status, 'verified_at' => now()]);
            try {
                app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
                $this->fail('Expected blocked status');
            } catch (ValidationException) {
                $this->assertDatabaseCount('erp_export_batches', 0);
            }
        }
        $slip->update(['status' => 'approved']);
        $slip->invoices()->first()->updateQuietly(['grand_total_amount' => '1.00']);
        $this->expectException(ValidationException::class);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
    }

    public function test_page_queue_preview_and_history(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $page = Livewire::test(ErpExports::class)
            ->assertSuccessful()
            ->assertSee('Finance Operations')
            ->assertSee('Buyer: Fixture Buyer')
            ->assertCanSeeTableRecords([$slip])
            ->mountAction(TestAction::make('prepare')->table($slip))
            ->assertActionMounted(TestAction::make('prepare')->table($slip));
        $html = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('Journal preview', $html);
        $this->assertStringContainsString('000045', $html);
        $this->assertStringContainsString('666,00', $html);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        Livewire::test(ErpExports::class)->assertCanNotSeeTableRecords([$slip])->set('activeTab', 'history')->assertCanSeeTableRecords([$slip]);
    }

    public function test_roles_cannot_access_page_export_or_download(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        foreach (['maker', 'approver'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user)->get('/admin/erp-exports')->assertForbidden();
            $this->get(route('erp-exports.download', $batch))->assertForbidden();
            try {
                app(ExportPaymentSlipToErp::class)->execute($slip, $user);
                $this->fail('Expected forbidden export');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_modal_exports_with_blank_vat_and_persists_it_on_invoice(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        Livewire::test(ErpExports::class)
            ->callAction(TestAction::make('prepare')->table($slip), ['vat_numbers' => [$invoice->id => null]])
            ->assertHasNoActionErrors()->assertSet('activeTab', 'history')->assertFileDownloaded('PS-FIXTURE-001-ERP.xlsx');
        $this->assertSame('exported', $slip->fresh()->status);
        $this->assertNull($invoice->fresh()->vat_invoice_number);
        $this->assertDatabaseCount('erp_export_items', 1);
        $this->assertStringNotContainsString('vat_numbers', PaymentSlipAudit::first()->toJson());
    }

    public function test_preview_updates_vat_and_matches_download(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        $page = Livewire::test(ErpExports::class)->mountAction(TestAction::make('prepare')->table($slip))
            ->setActionData(['vat_numbers' => [$invoice->id => '000777']]);
        $html = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('000777', $html);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('000777', $invoice->fresh()->vat_invoice_number);
        $batch = ErpExportBatch::firstOrFail();
        $book = IOFactory::load(Storage::disk('local')->path($batch->file_path));
        $this->assertSame('000777', $book->getSheet(0)->getCell('AV7')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_structural_failures_block_modal_and_no_tax_invoice_is_supported(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->invoices()->update(['tax_addition_amount' => 0, 'tax_deduction_amount' => 0, 'grand_total_amount' => 300]);
        $rows = app(ErpJournalBuilder::class)->build($slip);
        $this->assertCount(6, $rows);
        $slip->invoices()->first()->items()->update(['coa_id' => null, 'coa_code_snapshot' => null, 'coa_name_snapshot' => null]);
        Livewire::test(ErpExports::class)->callAction(TestAction::make('prepare')->table($slip))
            ->assertNotified('Export blocked');
        $this->assertDatabaseCount('erp_export_batches', 0);
        $this->assertSame('approved', $slip->fresh()->status);
    }

    public function test_invalid_amounts_empty_items_and_supplier_are_rejected(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $builder = app(ErpJournalBuilder::class);
        $slip->load('supplier', 'invoices.items');
        $item = $slip->invoices->first()->items->first();
        foreach (['-1.00', 'NaN', '1.001', '10000000000000000'] as $amount) {
            $item->setRawAttributes(array_merge($item->getAttributes(), ['subtotal_amount' => $amount]), true);
            try {
                $builder->build($slip);
                $this->fail('Expected invalid amount');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Invalid money value', $e->getMessage());
            }
        }
        $slip->refresh()->load('supplier', 'invoices.items');
        $slip->invoices->first()->setRelation('items', collect());
        try {
            $builder->build($slip);
            $this->fail('Expected no items');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no expense items', $e->getMessage());
        }
        $slip->supplier->code = '';
        $this->expectException(ValidationException::class);
        $builder->build($slip);
    }

    public function test_ready_excludes_every_other_status_and_cannot_edit_approved_slip(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        foreach (['draft', 'submitted', 'pending_approval', 'exported'] as $status) {
            $slip->update(['status' => $status]);
            Livewire::test(ErpExports::class)->assertCanNotSeeTableRecords([$slip]);
        }
        $slip->update(['status' => 'approved']);
        $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))->assertForbidden();
    }

    public function test_checker_can_save_item_coa_through_verification_form_without_changing_tax(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $coa = ChartOfAccount::create(['code' => '222', 'name' => 'Second account']);
        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);
        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.coa_id", $coa->id)->call('save')->assertHasNoFormErrors();
        $item = $slip->invoices()->first()->items()->first();
        $this->assertSame('222', $item->coa_code_snapshot);
        $this->assertSame('33.01', $item->invoice->tax_addition_amount);
        $this->assertSame('6.01', $item->invoice->tax_deduction_amount);
        $this->assertSame('327.00', $item->invoice->grand_total_amount);
        $maker = User::factory()->create()->assignRole('maker');
        $this->actingAs($maker);
        $slip->update(['status' => 'draft', 'created_by' => $maker->id]);
        $makerPage = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $this->assertStringNotContainsString('Second account', $makerPage->html());
    }

    public function test_checker_can_save_import_item_vat_invoice_number_during_verification(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update([
            'transaction_type' => 'import',
            'status' => 'submitted',
        ]);
        $slip->invoices()->update(['buyer_id' => $slip->buyer_id]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);

        $page->set(
            "data.invoices.{$invoiceKey}.items.{$itemKey}.vat_invoice_number",
            '04002600294208808',
        )->call('save')->assertHasNoFormErrors();

        $this->assertSame(
            '04002600294208808',
            $slip->invoices()->first()->items()->first()->vat_invoice_number,
        );
    }

    public function test_checker_can_save_import_invoice_vat_invoice_number_during_verification(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update([
            'transaction_type' => 'import',
            'status' => 'submitted',
        ]);
        $slip->invoices()->update(['buyer_id' => $slip->buyer_id]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);

        $page->assertSee('VAT Invoice No. Utama')
            ->set(
                "data.invoices.{$invoiceKey}.vat_invoice_number",
                '04002600305363254',
            )->call('save')->assertHasNoFormErrors();

        $this->assertSame(
            '04002600305363254',
            $slip->invoices()->first()->vat_invoice_number,
        );
    }

    public function test_import_invoice_receipt_number_is_readable_and_editable_during_verification(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update([
            'transaction_type' => PaymentSlip::TYPE_IMPORT,
            'status' => 'submitted',
            'invoice_receipt_number' => 'HIJ / IMP / 001 / VIII / 2026',
        ]);
        $slip->invoices()->update(['buyer_id' => $slip->buyer_id]);

        Livewire::test(ViewPaymentSlip::class, ['record' => $slip->id])
            ->assertSee('Nomor Tanda Terima Invoice')
            ->assertSet('data.invoice_receipt_number', 'HIJ / IMP / 001 / VIII / 2026');

        Livewire::test(EditPaymentSlip::class, ['record' => $slip->id])
            ->set('data.invoice_receipt_number', 'HIJ / IMP / 002 / VIII / 2026')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('HIJ / IMP / 002 / VIII / 2026', $slip->fresh()->invoice_receipt_number);
    }

    public function test_checker_with_maker_role_can_save_item_coa(): void
    {
        $this->checker->assignRole('maker');
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $coa = ChartOfAccount::create(['code' => '333', 'name' => 'Multi-role account']);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.coa_id", $coa->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('333', $slip->invoices()->first()->items()->first()->coa_code_snapshot);
    }

    public function test_missing_template_and_missing_download_are_safe(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        config(['erp-export.template' => storage_path('app/nonexistent-erp-template.xlsx')]);
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected invalid template');
        } catch (ValidationException) {
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
        config(['erp-export.template' => base_path('docs/vmc 5 -expeditor-.xlsx')]);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        Storage::disk('local')->delete($batch->file_path);
        $this->get(route('erp-exports.download', $batch))->assertNotFound();
        $this->assertDatabaseCount('erp_export_batches', 1);
    }

    public function test_vat_item_is_visible_on_export_itemized_and_hidden_on_general(): void
    {
        $eximMaker = $this->makerForDivision('EXIM');
        $this->actingAs($eximMaker);

        $exportPage = Livewire::test(CreateExportPaymentSlip::class)
            ->fillForm([
                'transaction_type' => PaymentSlip::TYPE_EXPORT,
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'invoices' => [[
                    'invoice_number' => 'EXP-001',
                    'invoice_date' => '2026-09-16',
                    'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                    'items' => [['item_name' => 'Handling', 'quantity' => 1, 'unit_price_amount' => 1000]],
                ]],
            ]);
        $exportPage->assertSeeHtml('VAT Invoice No. (Item)');

        $generalMaker = $this->makerForDivision('HR');
        $this->actingAs($generalMaker);
        $generalPage = Livewire::test(CreateGeneralPaymentSlip::class)
            ->fillForm([
                'transaction_type' => PaymentSlip::TYPE_GENERAL,
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_INVOICE_LEGACY,
                'invoices' => [[
                    'invoice_number' => 'NOTA-001',
                    'invoice_date' => '2026-09-16',
                    'items' => [['item_name' => 'Kertas A4', 'quantity' => 1, 'unit_price_amount' => 1000]],
                ]],
            ]);
        $generalPage->assertDontSeeHtml('VAT Invoice No. (Item)');
    }

    public function test_maker_can_save_vat_item_export_on_draft(): void
    {
        $maker = $this->makerForDivision('EXIM');
        $this->actingAs($maker);
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_EXPORT, 'status' => 'draft', 'created_by' => $maker->id, 'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $slip->invoices()->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.vat_invoice_number", 'VAT-ITEM-123')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('VAT-ITEM-123', $slip->invoices()->first()->items()->first()->vat_invoice_number);
    }

    public function test_checker_can_save_vat_item_export_on_submitted(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_EXPORT, 'status' => 'submitted', 'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $slip->invoices()->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.vat_invoice_number", 'VAT-ITEM-456')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('VAT-ITEM-456', $slip->invoices()->first()->items()->first()->vat_invoice_number);
    }

    public function test_export_item_vat_blocks_switch_to_invoice_tax_mode(): void
    {
        $this->actingAs($this->makerForDivision('EXIM'));
        $supplier = Supplier::create(['code' => 'VAT-GUARD-SUP', 'name' => 'VAT Guard Supplier', 'is_active' => true]);
        $buyer = Buyer::create(['code' => 'VAT-GUARD-BUY', 'name' => 'VAT Guard Buyer', 'is_active' => true]);
        $ppn = Tax::create(['code' => 'VAT-GUARD-PPN', 'name' => 'PPN 11%', 'rate' => 11, 'calculation_type' => 'addition', 'is_active' => true]);

        $page = Livewire::test(CreateExportPaymentSlip::class)->fillForm([
            'supplier_id' => $supplier->id,
            'invoices' => [[
                'buyer_id' => $buyer->id,
                'invoice_number' => 'EXP-VAT-GUARD',
                'invoice_date' => '2026-09-22',
                'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
                'items' => [[
                    'item_name' => 'Handling',
                    'quantity' => 1,
                    'unit_price_amount' => 1000,
                    'vat_invoice_number' => 'VAT-ITEM-GUARD',
                ]],
            ]],
        ]);

        $state = $page->get('data');
        $invoiceKey = array_key_first($state['invoices']);
        $page->set("data.invoices.{$invoiceKey}.bulk_ppn_tax_id", $ppn->id)
            ->callAction(TestAction::make('apply_ppn_to_items')->schemaComponent("invoices.{$invoiceKey}.bulk_ppn_actions"))
            ->assertHasErrors();

        $invoiceState = $page->get('data')['invoices'][$invoiceKey];
        $this->assertSame(PaymentSlip::TAX_MODE_ITEMIZED, $invoiceState['tax_calculation_mode']);
        $this->assertSame('VAT-ITEM-GUARD', array_values($invoiceState['items'])[0]['vat_invoice_number']);
    }

    public function test_export_journal_descriptions_use_correct_item_vat_and_fallback_to_invoice_vat(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_EXPORT, 'status' => 'approved', 'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $slip->invoices()->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);

        $invoice = $slip->invoices()->first();
        $slip->invoices()->where('id', '!=', $invoice->id)->delete();
        $invoice->updateQuietly(['vat_invoice_number' => 'VAT-MAIN-INV']);

        $item1 = $invoice->items()->first();
        $invoice->items()->where('id', '!=', $item1->id)->delete();

        $item1->updateQuietly(['vat_invoice_number' => 'VAT-ITM-1', 'tax_addition_amount' => 11, 'item_name' => 'Handling 1', 'line_number' => 1]);

        $item2 = $item1->replicate();
        $item2->vat_invoice_number = 'VAT-ITM-2';
        $item2->item_name = 'Handling 2';
        $item2->line_number = 2;
        $item2->saveQuietly();

        $item3 = $item1->replicate();
        $item3->vat_invoice_number = null; // Should fallback to VAT-MAIN-INV
        $item3->item_name = 'Handling 3';
        $item3->line_number = 3;
        $item3->saveQuietly();

        $item4 = $item1->replicate();
        $item4->vat_invoice_number = null;
        $item4->item_name = 'Handling 4';
        $item4->line_number = 4;
        $item4->saveQuietly();

        $invoice2 = $invoice->replicate();
        $invoice2->vat_invoice_number = null;
        $invoice2->invoice_number = '000046';
        $invoice2->saveQuietly();

        $item5 = $item1->replicate();
        $item5->invoice_id = $invoice2->id;
        $item5->vat_invoice_number = null; // Both item and invoice are empty, no prefix
        $item5->item_name = 'Handling 5';
        $item5->line_number = 1;
        $item5->saveQuietly();

        // Re-calculate totals to pass the guard
        foreach ([$invoice, $invoice2] as $inv) {
            $expense = $inv->items()->sum('subtotal_amount');
            $ppnTotal = $inv->items()->sum('tax_addition_amount');
            $pphTotal = $inv->items()->sum('tax_deduction_amount');
            $inv->updateQuietly([
                'subtotal_amount' => $expense,
                'tax_addition_amount' => $ppnTotal,
                'tax_deduction_amount' => $pphTotal,
                'grand_total_amount' => $expense + $ppnTotal - $pphTotal,
            ]);
            foreach ($inv->items as $itm) {
                $itm->updateQuietly(['net_amount' => $itm->subtotal_amount + $itm->tax_addition_amount - $itm->tax_deduction_amount]);
            }
        }

        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());

        // Find PPN rows for each item to check description and VAT number
        $ppnRows = collect($rows)->filter(fn ($row) => $row->rowType === 'PPN')->values();

        $this->assertCount(5, $ppnRows);
        $this->assertNull($ppnRows[0]->vatInvoiceNumber);
        $this->assertSame('VAT-ITM-1 -Fixture Buyer inv 000045-Fixture Supplier', $ppnRows[0]->description);

        $this->assertNull($ppnRows[1]->vatInvoiceNumber);
        $this->assertSame('VAT-ITM-2 -Fixture Buyer inv 000045-Fixture Supplier', $ppnRows[1]->description);

        $this->assertNull($ppnRows[2]->vatInvoiceNumber);
        $this->assertSame('VAT-MAIN-INV -Fixture Buyer inv 000045-Fixture Supplier', $ppnRows[2]->description);

        $this->assertNull($ppnRows[3]->vatInvoiceNumber);
        $this->assertSame('VAT-MAIN-INV -Fixture Buyer inv 000045-Fixture Supplier', $ppnRows[3]->description);

        $this->assertNull($ppnRows[4]->vatInvoiceNumber);
        $this->assertSame('Fixture Buyer inv 000046-Fixture Supplier', $ppnRows[4]->description);
    }

    public function test_import_journal_does_not_fallback_to_invoice_vat(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['transaction_type' => PaymentSlip::TYPE_IMPORT, 'status' => 'approved', 'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $slip->invoices()->update(['tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED]);
        $invoice = $slip->invoices()->first();
        $invoice->updateQuietly(['buyer_id' => $slip->buyer_id, 'vat_invoice_number' => 'SHOULD-NOT-FALLBACK']);

        $item = $invoice->items()->first();
        $item->updateQuietly([
            'tax_addition_amount' => 11,
            'vat_invoice_number' => null,
            'net_amount' => $item->subtotal_amount + 11 - $item->tax_deduction_amount,
        ]);

        $invoice->updateQuietly([
            'tax_addition_amount' => 11,
            'grand_total_amount' => $invoice->subtotal_amount + 11 - $invoice->tax_deduction_amount,
        ]);

        $rows = app(ErpJournalBuilder::class)->build($slip->fresh());

        $ppnRow = collect($rows)->firstWhere('rowType', 'PPN');
        $this->assertNotNull($ppnRow);
        $this->assertNull($ppnRow->vatInvoiceNumber); // No fallback for import
        $this->assertSame('Fixture Buyer inv 000045-Fixture Supplier', $ppnRow->description);
    }
}
