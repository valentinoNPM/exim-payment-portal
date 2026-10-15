<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Models\Division;
use App\Models\DocumentFile;
use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentFilePreviewAndExtractionTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Role::create(['name' => 'maker']);
        $division = Division::create(['code' => 'EXIM', 'name' => 'EXIM']);
        $this->maker = User::factory()->create(['division_id' => $division->id])->assignRole('maker');
        $this->supplier = Supplier::create(['code' => 'SUP-DOC', 'name' => 'Supplier Document']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->maker);
    }

    public function test_registrar_repairs_a_same_checksum_row_whose_file_is_missing(): void
    {
        $contents = '%PDF-1.4 same document';
        $stale = DocumentFile::create([
            'disk' => 'local', 'path' => 'invoice-uploads/missing.pdf', 'original_name' => 'missing.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => strlen($contents), 'checksum' => md5($contents), 'uploaded_at' => now(),
        ]);
        Storage::disk('local')->put('invoice-uploads/replacement.pdf', $contents);

        $oldMatch = DocumentFile::query()->where('disk', 'local')->where('checksum', md5($contents))->firstOrFail();
        $this->assertSame($stale->id, $oldMatch->id);
        $this->assertFalse(Storage::disk($oldMatch->disk)->exists($oldMatch->path));

        $ids = app(DocumentFileRegistrar::class)->registerLocalUploads(
            ['invoice-uploads/replacement.pdf'], ['invoice-uploads/replacement.pdf' => 'replacement.pdf'],
        );

        $this->assertSame([$stale->id], $ids);
        $this->assertSame('invoice-uploads/replacement.pdf', $stale->fresh()->path);
        $this->assertTrue(Storage::disk('local')->exists($stale->fresh()->path));
    }

    public function test_preview_returns_pdf_when_present_and_clear_message_when_missing(): void
    {
        Storage::disk('local')->put('invoice-uploads/present.pdf', '%PDF-1.4 present');
        $present = $this->document('invoice-uploads/present.pdf');
        $missing = $this->document('invoice-uploads/missing.pdf');

        $this->get(route('document-files.view', $present))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('document-files.view', $missing))
            ->assertNotFound()->assertSee('Berkas dokumen tidak ditemukan')->assertSee('ID '.$missing->id);
    }

    public function test_extraction_control_only_appears_for_draft_exim_edit_and_not_view(): void
    {
        $draft = $this->slip(PaymentSlip::TYPE_EXPORT, 'draft');
        $submitted = $this->slip(PaymentSlip::TYPE_IMPORT, 'submitted');
        $general = $this->slip(PaymentSlip::TYPE_GENERAL, 'draft');

        Livewire::test(EditPaymentSlip::class, ['record' => $draft->id])->assertSee('Extract Invoices via Gemini');
        Livewire::test(EditPaymentSlip::class, ['record' => $submitted->id])->assertDontSee('Extract Invoices via Gemini');
        Livewire::test(EditPaymentSlip::class, ['record' => $general->id])->assertDontSee('Extract Invoices via Gemini');
        Livewire::test(ViewPaymentSlip::class, ['record' => $draft->id])->assertDontSee('Extract Invoices via Gemini');
    }

    public function test_editor_extraction_persists_invoice_items_and_an_existing_document_reference(): void
    {
        $slip = $this->slip(PaymentSlip::TYPE_EXPORT, 'draft');
        $this->mock(GeminiInvoiceExtractor::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractWithReport')->once()->andReturnUsing(fn (array $files): array => [
                'successful' => [[
                    'path' => $files[0]['path'], 'original_name' => $files[0]['original_name'],
                    'invoices' => [[
                        'invoice_number' => 'INV-AI-001', 'invoice_date' => '2026-10-12',
                        'items' => [['item_name' => 'Freight', 'line_total' => 125000, 'unit_price' => 125000]],
                    ]],
                ]], 'failed' => [],
            ]);
        });

        $this->post(route('payment-slips.editor.extract-invoices', $slip), [
            'raw_pdf_files' => [UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')],
        ])->assertRedirect();

        $invoice = Invoice::query()->whereBelongsTo($slip)->where('invoice_number', 'INV-AI-001')->firstOrFail();
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Freight', $invoice->items()->value('item_name'));
        $this->assertNotNull($invoice->documentFile);
        $this->assertTrue(Storage::disk($invoice->documentFile->disk)->exists($invoice->documentFile->path));
    }

    private function slip(string $type, string $status): PaymentSlip
    {
        return PaymentSlip::create([
            'slip_number' => 'PS-'.strtoupper($type).'-'.uniqid(), 'transaction_type' => $type,
            'transaction_description' => $type === PaymentSlip::TYPE_GENERAL ? 'General' : null,
            'currency' => PaymentSlip::CURRENCY_IDR, 'tax_calculation_mode' => PaymentSlip::TAX_MODE_ITEMIZED,
            'supplier_id' => $this->supplier->id, 'status' => $status, 'created_by' => $this->maker->id,
        ]);
    }

    private function document(string $path): DocumentFile
    {
        return DocumentFile::create([
            'disk' => 'local', 'path' => $path, 'original_name' => basename($path),
            'mime_type' => 'application/pdf', 'size_bytes' => 16, 'checksum' => md5($path), 'uploaded_at' => now(),
        ]);
    }
}
