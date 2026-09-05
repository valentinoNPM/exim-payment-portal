<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class GeminiInvoiceExtractor
{
    protected string $prompt = <<<'EOT'
You are an expert accounting system AI. Extract every commercial invoice from the provided document content.

Strict rules:
1. A PDF can contain multiple invoices on separate pages. Recognize labels including "INVOICE NUMBER", "INVOICE NO", "INV NO", and "NO. INVOICE".
2. Ignore logistics-only documents such as Sea Waybill, Cargo Receipt, delivery notes, and supporting attachments that do not contain a commercial invoice table.
3. Extract invoice_number from the invoice identifier and invoice_date as YYYY-MM-DD.
4. Extract every base charge or service description as an item.
5. Set qty to 1 unless an actual billable quantity is explicitly stated.
6. Extract the final billed amount for each item as original_price. When an item table has RATE, VAT, PPH, and TOTAL columns, use that item's TOTAL column. Do not calculate or return VAT/PPH separately.
7. Do not create items from SUB TOTAL, GRAND TOTAL, VAT, PPN, PPH, INVOICE TOTAL, or other standalone tax/summary rows.
8. Return numeric JSON values for qty and original_price, without currency symbols or thousands separators.

Output strictly as a JSON array:
[
  {
    "invoice_number": "string",
    "invoice_date": "YYYY-MM-DD",
    "items": [
      {
        "item_name": "string",
        "qty": 1,
        "original_price": 123456
      }
    ]
  }
]
EOT;

    public function extract(array $pdfPaths): array
    {
        $extractedData = [];

        foreach ($pdfPaths as $pdfPath) {
            $extractedData = array_merge($extractedData, $this->extractFile($pdfPath, basename($pdfPath)));
        }

        return $extractedData;
    }

    /**
     * @param  list<array{path: string, original_name: string}>  $files
     * @return array{successful: list<array{path: string, original_name: string, invoices: array}>, failed: list<array{path: string, original_name: string, message: string}>}
     */
    public function extractWithReport(array $files): array
    {
        $successful = [];
        $failed = [];

        foreach ($files as $file) {
            try {
                $successful[] = [
                    'path' => $file['path'],
                    'original_name' => $file['original_name'],
                    'invoices' => $this->extractFile($file['path'], $file['original_name']),
                ];
            } catch (Throwable $exception) {
                Log::error('GeminiInvoiceExtractor: File extraction failed', [
                    'original_name' => $file['original_name'],
                    'file' => $file['path'],
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                $failed[] = [
                    'path' => $file['path'],
                    'original_name' => $file['original_name'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return ['successful' => $successful, 'failed' => $failed];
    }

    protected function extractFile(string $pdfPath, string $originalName): array
    {
        $startedAt = microtime(true);
        $fullPath = $this->resolvePdfPath($pdfPath);
        $text = $this->findUsableLocalText($fullPath, $pdfPath);
        $result = [];

        try {

            if ($text !== null) {
                Log::info('GeminiInvoiceExtractor: Using text prompt', [
                    'file' => $pdfPath,
                    'original_name' => $originalName,
                    'text_length' => mb_strlen($text),
                ]);

                $result = $this->validateExtractedInvoices($this->extractViaTextPrompt($text));

                if ($result === []) {
                    Log::warning('GeminiInvoiceExtractor: Text prompt returned no valid invoices; falling back to multimodal', [
                        'file' => $pdfPath,
                        'original_name' => $originalName,
                    ]);
                }
            }

            if ($result === []) {
                Log::info('GeminiInvoiceExtractor: Using Tier 3 (Multimodal Vision)', [
                    'file' => $pdfPath,
                    'original_name' => $originalName,
                ]);

                $result = $this->validateExtractedInvoices($this->extractViaMultimodal([$pdfPath]));
            }

            if ($result === []) {
                throw new RuntimeException("No valid invoices were found in PDF: {$pdfPath}");
            }

            $itemCount = array_sum(array_map(
                static fn (array $invoice): int => count($invoice['items']),
                $result,
            ));

            Log::info('GeminiInvoiceExtractor: Extraction completed', [
                'file' => $pdfPath,
                'original_name' => $originalName,
                'invoice_count' => count($result),
                'item_count' => $itemCount,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $result;
        } catch (Throwable $exception) {
            if ($text !== null && str_contains($exception->getMessage(), 'Gemini')) {
                throw new RuntimeException(
                    'Teks PDF berhasil dibaca, tetapi layanan AI gagal: '.$exception->getMessage(),
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    protected function findUsableLocalText(string $fullPath, string $displayPath): ?string
    {
        if (config('services.gemini.markitdown.enabled', true)) {
            try {
                $markdownText = $this->extractViaMarkitdown($fullPath);

                if ($this->isUsableExtractedText($markdownText)) {
                    Log::info('GeminiInvoiceExtractor: Tier 1 (MarkItDown) produced usable text', [
                        'file' => $displayPath,
                        'text_length' => mb_strlen(trim((string) $markdownText)),
                    ]);

                    return trim((string) $markdownText);
                }

                Log::info('GeminiInvoiceExtractor: Tier 1 (MarkItDown) rejected', [
                    'file' => $displayPath,
                    'reason' => 'empty or missing invoice markers',
                    'text_length' => mb_strlen(trim((string) $markdownText)),
                ]);
            } catch (Throwable $exception) {
                Log::warning('GeminiInvoiceExtractor: Tier 1 (MarkItDown) failed', [
                    'file' => $displayPath,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        try {
            $plainText = $this->extractViaPdfParser($fullPath);

            if ($this->isUsableExtractedText($plainText)) {
                Log::info('GeminiInvoiceExtractor: Tier 2 (PdfParser) produced usable text', [
                    'file' => $displayPath,
                    'text_length' => mb_strlen(trim((string) $plainText)),
                ]);

                return trim((string) $plainText);
            }

            Log::info('GeminiInvoiceExtractor: Tier 2 (PdfParser) rejected', [
                'file' => $displayPath,
                'reason' => 'empty or missing invoice markers',
                'text_length' => mb_strlen(trim((string) $plainText)),
            ]);
        } catch (Throwable $exception) {
            Log::warning('GeminiInvoiceExtractor: Tier 2 (PdfParser) failed', [
                'file' => $displayPath,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    protected function isUsableExtractedText(?string $text): bool
    {
        $text = trim((string) $text);
        $minimumLength = (int) config('services.gemini.minimum_text_length', 100);

        if (mb_strlen($text) < $minimumLength) {
            return false;
        }

        return preg_match('/\b(?:invoice|inv\.?\s*(?:no|number)|no\.?\s*invoice)\b/i', $text) === 1;
    }

    protected function validateExtractedInvoices(array $invoices): array
    {
        $validInvoices = [];

        foreach ($invoices as $invoice) {
            if (! is_array($invoice)) {
                continue;
            }

            $invoiceNumber = trim((string) ($invoice['invoice_number'] ?? ''));
            $invoiceDate = trim((string) ($invoice['invoice_date'] ?? ''));
            $items = $invoice['items'] ?? null;

            if ($invoiceNumber === '' || ! $this->isValidDate($invoiceDate) || ! is_array($items)) {
                continue;
            }

            $validItems = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemName = trim((string) ($item['item_name'] ?? ''));
                $quantity = $item['qty'] ?? 1;
                $price = $item['original_price'] ?? null;

                if ($itemName === '' || ! is_numeric($quantity) || (float) $quantity <= 0 || ! is_numeric($price) || (float) $price < 0) {
                    continue;
                }

                $validItems[] = [
                    'item_name' => $itemName,
                    'qty' => (float) $quantity,
                    'original_price' => (float) $price,
                ];
            }

            if ($validItems === []) {
                continue;
            }

            $validInvoices[] = [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'items' => $validItems,
            ];
        }

        return $validInvoices;
    }

    protected function isValidDate(string $date): bool
    {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsedDate !== false && $parsedDate->format('Y-m-d') === $date;
    }

    protected function resolvePdfPath(string $pdfPath): string
    {
        if (Storage::disk('local')->exists($pdfPath)) {
            return Storage::disk('local')->path($pdfPath);
        }

        if (file_exists($pdfPath)) {
            return $pdfPath;
        }

        throw new RuntimeException("Cannot read PDF file: {$pdfPath}");
    }

    protected function extractViaMarkitdown(string $filePath): ?string
    {
        $pythonPath = config('services.gemini.markitdown.python_path', 'python');
        $result = Process::path(dirname($filePath))->run([
            $pythonPath,
            '-m',
            'markitdown',
            $filePath,
        ]);

        if ($result->successful()) {
            return $result->output();
        }

        throw new RuntimeException('MarkItDown CLI error: '.$result->errorOutput());
    }

    protected function extractViaPdfParser(string $filePath): ?string
    {
        return (new Parser)->parseFile($filePath)->getText();
    }

    protected function extractViaTextPrompt(string $textContent): array
    {
        return $this->parseGeminiResponse(
            Http::timeout(60)->post($this->geminiEndpoint(), [
                'contents' => [[
                    'parts' => [
                        ['text' => $this->prompt],
                        ['text' => "Here is the extracted document content:\n\n".$textContent],
                    ],
                ]],
                'generationConfig' => ['response_mime_type' => 'application/json'],
            ]),
        );
    }

    protected function extractViaMultimodal(array $pdfPaths): array
    {
        $parts = [];

        foreach ($pdfPaths as $pdfPath) {
            $fullPath = $this->resolvePdfPath($pdfPath);
            $pdfContent = file_get_contents($fullPath);

            if ($pdfContent === false) {
                throw new RuntimeException("Failed to read PDF content: {$pdfPath}");
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data' => base64_encode($pdfContent),
                ],
            ];
        }

        $parts[] = ['text' => $this->prompt];

        return $this->parseGeminiResponse(
            Http::timeout(180)->post($this->geminiEndpoint(), [
                'contents' => [['parts' => $parts]],
                'generationConfig' => ['response_mime_type' => 'application/json'],
            ]),
        );
    }

    protected function geminiEndpoint(): string
    {
        $apiKey = config('services.gemini.api_key');
        $model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            throw new RuntimeException('Gemini API Key is missing.');
        }

        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    }

    protected function parseGeminiResponse(Response $response): array
    {
        if ($response->failed()) {
            $requestId = $response->header('x-request-id') ?? $response->header('x-goog-request-id');
            $responseJson = $response->json();
            $safeResponse = is_array($responseJson) && isset($responseJson['error'])
                ? ['error' => $responseJson['error']]
                : Str::limit($response->body(), 2000);

            Log::error('Gemini API request failed', [
                'status' => $response->status(),
                'request_id' => $requestId,
                'response' => $safeResponse,
            ]);

            throw new RuntimeException('Gemini tidak tersedia (HTTP '.$response->status().').');
        }

        $jsonString = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($jsonString)) {
            throw new RuntimeException('Unexpected response structure from Gemini API');
        }

        $data = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new RuntimeException('Failed to decode JSON from Gemini: '.json_last_error_msg());
        }

        return $data;
    }
}
