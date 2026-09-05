<?php

namespace Tests\Unit;

use App\Services\GeminiInvoiceExtractor;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GeminiInvoiceExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('invoices/test.pdf', 'fake-pdf');
        config()->set('services.gemini.markitdown.enabled', true);
        config()->set('services.gemini.minimum_text_length', 20);
    }

    public function test_whitespace_preprocessors_fall_back_directly_to_multimodal(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = "\r\n";
        $extractor->pdfParserText = '';
        $extractor->multimodalResult = [$this->validInvoice()];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertCount(1, $result);
        $this->assertSame(0, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_usable_markitdown_text_uses_one_text_request(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = 'INVOICE NUMBER INV-001 with enough invoice table content';
        $extractor->textResult = [$this->validInvoice()];

        $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(0, $extractor->pdfParserRequests);
        $this->assertSame(0, $extractor->multimodalRequests);
    }

    public function test_pdf_parser_is_used_when_markitdown_text_is_not_usable(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = 'metadata only';
        $extractor->pdfParserText = 'Inv No INV-001 with enough invoice table content';
        $extractor->textResult = [$this->validInvoice()];

        $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(1, $extractor->pdfParserRequests);
        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(0, $extractor->multimodalRequests);
    }

    public function test_empty_text_response_falls_back_to_multimodal(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = 'INVOICE NUMBER INV-001 with enough invoice table content';
        $extractor->textResult = [];
        $extractor->multimodalResult = [$this->validInvoice()];

        $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_invalid_invoice_structures_are_rejected_and_fall_back(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = 'INVOICE NUMBER INV-001 with enough invoice table content';
        $extractor->textResult = [[
            'invoice_number' => '',
            'invoice_date' => '03/09/2026',
            'items' => [],
        ]];
        $extractor->multimodalResult = [$this->validInvoice()];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertSame('INV-001', $result[0]['invoice_number']);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_all_tiers_returning_no_valid_invoice_throws_an_exception(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = '';
        $extractor->pdfParserText = '';
        $extractor->multimodalResult = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid invoices were found');

        $extractor->extract(['invoices/test.pdf']);
    }

    public function test_invalid_items_are_removed_without_discarding_valid_items(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->markitdownText = '';
        $extractor->pdfParserText = '';
        $invoice = $this->validInvoice();
        $invoice['items'][] = ['item_name' => '', 'qty' => 0, 'original_price' => -1];
        $extractor->multimodalResult = [$invoice];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertCount(1, $result[0]['items']);
        $this->assertSame(1.0, $result[0]['items'][0]['qty']);
        $this->assertSame(400000.0, $result[0]['items'][0]['original_price']);
    }

    public function test_report_keeps_successful_files_when_another_file_fails_without_retry(): void
    {
        Storage::disk('local')->put('invoices/success.pdf', 'fake-success');
        Storage::disk('local')->put('invoices/failure.pdf', 'fake-failure');
        $extractor = new PartialFailureGeminiInvoiceExtractor;

        $report = $extractor->extractWithReport([
            ['path' => Storage::disk('local')->path('invoices/success.pdf'), 'original_name' => 'invoice-success.pdf'],
            ['path' => Storage::disk('local')->path('invoices/failure.pdf'), 'original_name' => 'invoice-failure.pdf'],
        ]);

        $this->assertCount(1, $report['successful']);
        $this->assertCount(1, $report['failed']);
        $this->assertSame('invoice-success.pdf', $report['successful'][0]['original_name']);
        $this->assertSame('invoice-failure.pdf', $report['failed'][0]['original_name']);
        $this->assertStringContainsString('Teks PDF berhasil dibaca', $report['failed'][0]['message']);
        $this->assertSame(2, $extractor->textRequests);
    }

    private function validInvoice(): array
    {
        return [
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-03',
            'items' => [[
                'item_name' => 'Custom Clearance',
                'qty' => 1,
                'original_price' => 400000,
            ]],
        ];
    }
}

class PartialFailureGeminiInvoiceExtractor extends GeminiInvoiceExtractor
{
    public int $textRequests = 0;

    private string $currentFile = '';

    protected function extractViaMarkitdown(string $filePath): ?string
    {
        $this->currentFile = basename($filePath);

        return 'INVOICE NUMBER INV-001 with enough invoice table content';
    }

    protected function extractViaTextPrompt(string $textContent): array
    {
        $this->textRequests++;

        if ($this->currentFile === 'failure.pdf') {
            throw new RuntimeException('Gemini tidak tersedia (HTTP 503).');
        }

        return [[
            'invoice_number' => 'INV-001',
            'invoice_date' => '2026-09-03',
            'items' => [[
                'item_name' => 'Custom Clearance',
                'qty' => 1,
                'original_price' => 400000,
            ]],
        ]];
    }
}

class FakeGeminiInvoiceExtractor extends GeminiInvoiceExtractor
{
    public ?string $markitdownText = null;

    public ?string $pdfParserText = null;

    public array $textResult = [];

    public array $multimodalResult = [];

    public int $pdfParserRequests = 0;

    public int $textRequests = 0;

    public int $multimodalRequests = 0;

    protected function extractViaMarkitdown(string $filePath): ?string
    {
        return $this->markitdownText;
    }

    protected function extractViaPdfParser(string $filePath): ?string
    {
        $this->pdfParserRequests++;

        return $this->pdfParserText;
    }

    protected function extractViaTextPrompt(string $textContent): array
    {
        $this->textRequests++;

        return $this->textResult;
    }

    protected function extractViaMultimodal(array $pdfPaths): array
    {
        $this->multimodalRequests++;

        return $this->multimodalResult;
    }
}
