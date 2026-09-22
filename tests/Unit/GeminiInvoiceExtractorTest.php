<?php

namespace Tests\Unit;

use App\Services\GeminiInvoiceExtractor;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        config()->set('services.gemini.minimum_text_length', 20);
    }

    public function test_whitespace_preprocessors_fall_back_directly_to_multimodal(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = "\r\n";
        $extractor->pdfParserText = "\r\n";
        $extractor->multimodalResult = [$this->validInvoice()];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertCount(1, $result);
        $this->assertSame(0, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_usable_pdf_text_uses_one_text_request(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = 'INVOICE NUMBER INV-001 with enough invoice table content';
        $extractor->textResult = [$this->validInvoice()];

        $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(1, $extractor->pdfParserRequests);
        $this->assertSame(0, $extractor->multimodalRequests);
    }

    public function test_pdf_parser_failure_falls_back_to_pdf_input(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserThrows = true;
        $extractor->multimodalResult = [$this->validInvoice()];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertCount(1, $result);
        $this->assertSame(0, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_alternative_invoice_marker_uses_text_request(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
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
        $extractor->pdfParserText = 'INVOICE NUMBER INV-001 with enough invoice table content';
        $extractor->textResult = [];
        $extractor->multimodalResult = [$this->validInvoice()];

        $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_invalid_invoice_structures_are_rejected_and_fall_back(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = 'INVOICE NUMBER INV-001 with enough invoice table content';
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
        $extractor->pdfParserText = '';
        $extractor->multimodalResult = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invoice utama belum dapat ditentukan');

        $extractor->extract(['invoices/test.pdf']);
    }

    public function test_invalid_items_are_removed_without_discarding_valid_items(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
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

    public function test_two_consecutive_requests_get_slots_at_least_4200ms_apart(): void
    {
        $extractor = new ThrottleTestingGeminiInvoiceExtractor;

        $slot1 = $extractor->publicReserveThrottleSlot();
        $slot2 = $extractor->publicReserveThrottleSlot();

        $this->assertGreaterThanOrEqual(4200, $slot2 - $slot1);
    }

    public function test_twenty_simultaneous_reservations_get_distinct_slots_without_expiry_issues(): void
    {
        $extractor = new ThrottleTestingGeminiInvoiceExtractor;
        $slots = [];

        for ($i = 0; $i < 20; $i++) {
            $slots[] = $extractor->publicReserveThrottleSlot();
        }

        for ($i = 1; $i < 20; $i++) {
            $this->assertGreaterThanOrEqual(4200, $slots[$i] - $slots[$i - 1]);
        }
    }

    public function test_lock_timeout_yields_clear_runtime_exception(): void
    {
        $mockLock = \Mockery::mock(Lock::class);
        $mockLock->shouldReceive('block')->with(30)->andThrow(new LockTimeoutException);

        Cache::shouldReceive('lock')->with('gemini_api_throttle_lock', 10)->andReturn($mockLock);
        Cache::makePartial();

        $extractor = new ThrottleTestingGeminiInvoiceExtractor;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sistem sedang sibuk memproses antrean AI. Silakan coba beberapa saat lagi.');

        $extractor->publicReserveThrottleSlot();
    }

    public function test_400_is_not_retried_and_returns_immediately(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);

        $extractor = new ThrottleTestingGeminiInvoiceExtractor;
        $report = $extractor->extractWithReport([
            ['path' => Storage::disk('local')->path('invoices/test.pdf'), 'original_name' => 'test.pdf'],
        ]);

        $this->assertCount(1, $report['failed']);
        Http::assertSentCount(1);
        $this->assertSame(0, $extractor->sleepCalls); // No retries = no sleep
    }

    public function test_transient_gemini_errors_are_retried_and_acquire_new_slots(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        Http::fakeSequence()
            ->push(['error' => ['message' => 'High demand']], 503)
            ->push(['error' => ['message' => 'Too many requests']], 429)
            ->push($this->successfulGeminiResponse(), 200);

        $extractor = new ThrottleTestingGeminiInvoiceExtractor;
        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertCount(1, $result);
        Http::assertSentCount(3);
        $this->assertSame(3, $extractor->reserveCalls);
    }

    public function test_connection_error_is_retried_and_throws_connection_exception_when_all_attempts_fail(): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        // Fake throwing a ConnectionException for all 4 attempts
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $extractor = new ThrottleTestingGeminiInvoiceExtractor;

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection timed out');

        $extractor->extract(['invoices/test.pdf']);
    }

    public function test_mixed_sequence_returns_connection_exception_and_attempts_exactly_four_times(): void
    {
        config()->set('services.gemini.api_key', 'test-key');

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                return Http::response(['error' => ['message' => 'High demand']], 503);
            }
            throw new ConnectionException('Connection timed out');
        });

        $extractor = new ThrottleTestingGeminiInvoiceExtractor;

        try {
            $extractor->extract(['invoices/test.pdf']);
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $this->assertSame(4, $attempts);
        $this->assertSame(4, $extractor->reserveCalls);
    }

    private function successfulGeminiResponse(): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([$this->validInvoice()], JSON_THROW_ON_ERROR),
                    ]],
                ],
            ]],
        ];
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

    public function test_expeditors_interleaved_tax_label_does_not_replace_billing_number(): void
    {
        $cases = [
            ['E832772154', 'R831194965'],
            ['E832772368', '6831245447'],
            ['E832772363', '6831245436'],
        ];

        foreach ($cases as [$expectedInvoiceNumber, $signatureReference]) {
            $extractor = new FakeGeminiInvoiceExtractor;
            $extractor->pdfParserText = implode("\n", [
                'INVOICE',
                'PT. Expeditors Indonesia',
                'INVOICE DATE',
                'INVOICE NUMBER',
                'YOUR REFERENCE',
                'CLIENT NO: G3042819 04/08/26',
                "PT. HANSOLL INDO JAVA TAX No: {$expectedInvoiceNumber}",
                '018826347015000',
                "FZ 01 {$signatureReference}",
                'AUTHORISED SIGNATURE',
            ]);
            $invoice = $this->validInvoice();
            $invoice['invoice_number'] = $signatureReference;
            $extractor->textResult = [$invoice];

            $result = $extractor->extract(['invoices/test.pdf']);

            $this->assertSame($expectedInvoiceNumber, $result[0]['invoice_number']);
        }
    }

    public function test_multiple_expeditors_numbers_are_not_reconciled_by_guessing(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = "Expeditors INVOICE NUMBER E832794415\nINVOICE NUMBER E832794416";
        $extractor->textResult = [$this->validInvoice()];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertSame('INV-001', $result[0]['invoice_number']);
    }

    public function test_supplier_hint_does_not_silently_filter_multiple_results_without_rechecking_the_pdf(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = 'INVOICE NUMBER FNS-001 and supporting INVOICE NUMBER UOL-001 with enough invoice table content';
        $first = $this->validInvoice();
        $first['invoice_number'] = 'FNS-001';
        $first['issuer_supplier_name'] = 'PT. FNS TRANSBUANA';
        $supporting = $this->validInvoice();
        $supporting['invoice_number'] = 'UOL-001';
        $supporting['issuer_supplier_name'] = 'PT Universe Optima Logistics';
        $extractor->textResult = [$first, $supporting];
        $extractor->multimodalResult = [$first];

        $report = $extractor->extractWithReport([[
            'path' => Storage::disk('local')->path('invoices/test.pdf'),
            'original_name' => 'bundle.pdf',
        ]], 'PT FNS Transbuana');

        $this->assertCount(1, $report['successful']);
        $this->assertSame(['FNS-001'], array_column($report['successful'][0]['invoices'], 'invoice_number'));
        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_supplier_name_difference_does_not_discard_a_valid_invoice_in_either_input_path(): void
    {
        foreach ([true, false] as $useText) {
            $extractor = new FakeGeminiInvoiceExtractor;
            $extractor->pdfParserText = $useText ? 'INVOICE NUMBER INV-001 with enough invoice table content' : '';
            $invoice = $this->validInvoice();
            $invoice['issuer_supplier_name'] = 'FNS';
            $extractor->textResult = [$invoice];
            $extractor->multimodalResult = [$invoice];

            $report = $extractor->extractWithReport([[
                'path' => Storage::disk('local')->path('invoices/test.pdf'),
                'original_name' => 'INV-001.pdf',
            ]], 'PT Fajar Nusantara Transbuana');

            $this->assertCount(1, $report['successful']);
            $this->assertSame([], $report['failed']);
            $this->assertSame('FNS', $report['successful'][0]['invoices'][0]['issuer_supplier_name']);
            $this->assertSame($useText ? 0 : 1, $extractor->multimodalRequests);
        }
    }

    public function test_multiple_main_invoices_from_text_are_rechecked_with_multimodal_and_reduced_to_one(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = 'INVOICE NUMBER FNS-001 INVOICE NUMBER FNS-002 with enough invoice table content';
        $first = $this->validInvoice();
        $first['invoice_number'] = 'FNS-001';
        $second = $this->validInvoice();
        $second['invoice_number'] = 'FNS-002';
        $extractor->textResult = [$first, $second];
        $extractor->multimodalResult = [$first];

        $result = $extractor->extract(['invoices/test.pdf']);

        $this->assertSame(['FNS-001'], array_column($result, 'invoice_number'));
        $this->assertSame(1, $extractor->textRequests);
        $this->assertSame(1, $extractor->multimodalRequests);
    }

    public function test_multiple_main_invoices_after_multimodal_are_rejected(): void
    {
        $extractor = new FakeGeminiInvoiceExtractor;
        $extractor->pdfParserText = '';
        $first = $this->validInvoice();
        $second = $this->validInvoice();
        $second['invoice_number'] = 'INV-002';
        $extractor->multimodalResult = [$first, $second];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Satu file PDF hanya boleh berisi satu invoice utama');

        $extractor->extract(['invoices/test.pdf']);
    }
}

class ThrottleTestingGeminiInvoiceExtractor extends GeminiInvoiceExtractor
{
    public int $sleepCalls = 0;

    public int $reserveCalls = 0;

    protected function extractViaPdfParser(string $filePath): ?string
    {
        return 'INVOICE NUMBER INV-001 with enough invoice table content';
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleepCalls++;
        // Don't actually sleep in tests to keep them fast
    }

    protected function reserveThrottleSlot(): int
    {
        $this->reserveCalls++;

        return parent::reserveThrottleSlot();
    }

    public function publicReserveThrottleSlot(): int
    {
        return parent::reserveThrottleSlot();
    }
}

class PartialFailureGeminiInvoiceExtractor extends GeminiInvoiceExtractor
{
    public int $textRequests = 0;

    private string $currentFile = '';

    protected function extractViaPdfParser(string $filePath): ?string
    {
        $this->currentFile = basename($filePath);

        return 'INVOICE NUMBER INV-001 with enough invoice table content';
    }

    protected function extractViaTextPrompt(string $textContent, ?string $payableSupplierName = null, ?string $originalName = null): array
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
    public bool $pdfParserThrows = false;

    public ?string $pdfParserText = null;

    public array $textResult = [];

    public array $multimodalResult = [];

    public int $pdfParserRequests = 0;

    public int $textRequests = 0;

    public int $multimodalRequests = 0;

    protected function extractViaPdfParser(string $filePath): ?string
    {
        $this->pdfParserRequests++;

        if ($this->pdfParserThrows) {
            throw new RuntimeException('Unreadable PDF text layer');
        }

        return $this->pdfParserText;
    }

    protected function extractViaTextPrompt(string $textContent, ?string $payableSupplierName = null, ?string $originalName = null): array
    {
        $this->textRequests++;

        return $this->textResult;
    }

    protected function extractViaMultimodal(array $pdfPaths, ?string $payableSupplierName = null, ?string $originalName = null): array
    {
        $this->multimodalRequests++;

        return $this->multimodalResult;
    }
}
