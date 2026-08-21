<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiInvoiceExtractor
{
    public function extract(array $pdfPaths): array
    {
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_API_MODEL', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $parts = [];
        
        // Add PDF files to parts
        foreach ($pdfPaths as $pdfPath) {
            // Check if file exists in storage
            if (Storage::disk('local')->exists($pdfPath)) {
                $fullPath = Storage::disk('local')->path($pdfPath);
                $pdfContent = file_get_contents($fullPath);
            } elseif (file_exists($pdfPath)) {
                $pdfContent = file_get_contents($pdfPath);
            } else {
                throw new \Exception("Cannot read PDF file: {$pdfPath}");
            }

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

        // Add instructions text part
        $prompt = <<<EOT
You are an expert accounting system AI. Your job is to extract invoice data from the provided PDF(s).
Strict rules:
1. Target only pages that contain the main "INVOICE" label. Ignore logistics pages like Sea Waybill or Cargo Receipt.
2. Extract the main invoice_number from the PDF (e.g. from "INVOICE NUMBER").
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

        $parts[] = [
            'text' => $prompt,
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

        if ($response->failed()) {
            Log::error('Gemini API Error: ' . $response->body());
            throw new \Exception('Failed to communicate with Gemini API: ' . $response->status());
        }

        $result = $response->json();
        
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new \Exception('Unexpected response structure from Gemini API');
        }

        $jsonString = $result['candidates'][0]['content']['parts'][0]['text'];
        $data = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to decode JSON from Gemini: ' . json_last_error_msg());
        }

        return $data;
    }
}
