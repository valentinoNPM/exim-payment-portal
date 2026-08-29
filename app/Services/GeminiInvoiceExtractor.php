<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class GeminiInvoiceExtractor
{
    protected string $prompt = <<<'EOT'
You are an expert accounting system AI. Your job is to extract invoice data from the provided invoice content.
Strict rules:
1. Target only the main "INVOICE" details. Ignore logistics details like Sea Waybill or Cargo Receipt.
2. Extract the main invoice_number (e.g. from "INVOICE NUMBER").
3. Extract invoice_date from "INVOICE DATE" and format it as YYYY-MM-DD.
4. Extract the items from the item table (e.g., CARRIER BL FEE, OM DOCUMENT ASSEMBLY, etc.).
5. Set qty to 1 for all items.
6. Extract the nominal amount to original_price (parse as number).
7. IGNORING total calculation rows like "SUB TOTAL", "VAT", and "INVOICE TOTAL".

Output strictly as a JSON array where each object represents an invoice with the following schema:
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
            $fullPath = $this->resolvePdfPath($pdfPath);

            // Tier 1: Try MarkItDown
            if (config('services.gemini.markitdown.enabled', true)) {
                try {
                    $markdownText = $this->extractViaMarkitdown($fullPath);
                    if ($markdownText) {
                        Log::info("GeminiInvoiceExtractor: Using Tier 1 (MarkItDown) for {$pdfPath}");
                        $result = $this->extractViaTextPrompt($markdownText);
                        $extractedData = array_merge($extractedData, $result);

                        continue; // Success, go to next file
                    }
                } catch (\Exception $e) {
                    Log::warning("GeminiInvoiceExtractor: Tier 1 (MarkItDown) failed for {$pdfPath}. Error: ".$e->getMessage());
                }
            }

            // Tier 2: Try Smalot PDFParser
            try {
                $plainText = $this->extractViaPdfParser($fullPath);
                if (trim($plainText) !== '') {
                    Log::info("GeminiInvoiceExtractor: Using Tier 2 (PdfParser) for {$pdfPath}");
                    $result = $this->extractViaTextPrompt($plainText);
                    $extractedData = array_merge($extractedData, $result);

                    continue; // Success, go to next file
                }
            } catch (\Exception $e) {
                Log::warning("GeminiInvoiceExtractor: Tier 2 (PdfParser) failed for {$pdfPath}. Error: ".$e->getMessage());
            }

            // Tier 3: Fallback to Multimodal (Raw PDF)
            Log::info("GeminiInvoiceExtractor: Using Tier 3 (Multimodal Vision) for {$pdfPath}");
            $result = $this->extractViaMultimodal([$pdfPath]); // Process one by one in fallback
            $extractedData = array_merge($extractedData, $result);
        }

        return $extractedData;
    }

    protected function resolvePdfPath(string $pdfPath): string
    {
        if (Storage::disk('local')->exists($pdfPath)) {
            return Storage::disk('local')->path($pdfPath);
        } elseif (file_exists($pdfPath)) {
            return $pdfPath;
        } else {
            throw new \Exception("Cannot read PDF file: {$pdfPath}");
        }
    }

    protected function extractViaMarkitdown(string $filePath): ?string
    {
        $pythonPath = config('services.gemini.markitdown.python_path', 'python');
        $command = "{$pythonPath} -m markitdown ".escapeshellarg($filePath);

        $result = Process::run($command);

        if ($result->successful()) {
            return $result->output();
        }

        throw new \Exception('MarkItDown CLI error: '.$result->errorOutput());
    }

    protected function extractViaPdfParser(string $filePath): ?string
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($filePath);

        return $pdf->getText();
    }

    protected function extractViaTextPrompt(string $textContent): array
    {
        $apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        $model = config('services.gemini.model', env('GEMINI_API_MODEL', 'gemini-1.5-flash'));

        if (empty($apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $this->prompt],
                        ['text' => "Here is the extracted document content:\n\n".$textContent],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
            ],
        ];

        $response = Http::timeout(60)->post($endpoint, $payload);

        return $this->parseGeminiResponse($response);
    }

    protected function extractViaMultimodal(array $pdfPaths): array
    {
        $apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
        $model = config('services.gemini.model', env('GEMINI_API_MODEL', 'gemini-1.5-flash'));

        if (empty($apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $parts = [];

        foreach ($pdfPaths as $pdfPath) {
            $fullPath = $this->resolvePdfPath($pdfPath);
            $pdfContent = file_get_contents($fullPath);

            if ($pdfContent === false) {
                throw new \Exception("Failed to read PDF content: {$pdfPath}");
            }

            $base64 = base64_encode($pdfContent);
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data' => $base64,
                ],
            ];
        }

        $parts[] = [
            'text' => $this->prompt,
        ];

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
            ],
        ];

        $response = Http::timeout(180)->post($endpoint, $payload);

        return $this->parseGeminiResponse($response);
    }

    protected function parseGeminiResponse($response): array
    {
        if ($response->failed()) {
            Log::error('Gemini API Error: '.$response->body());
            throw new \Exception('Failed to communicate with Gemini API: '.$response->status());
        }

        $result = $response->json();

        if (! isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Unexpected response structure from Gemini API');
        }

        $jsonString = $result['candidates'][0]['content']['parts'][0]['text'];
        $data = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to decode JSON from Gemini: '.json_last_error_msg());
        }

        return $data;
    }
}
