<?php

use App\Models\PaymentSlip;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

// Read-only production snapshot. Run from the application's working directory.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$slips = PaymentSlip::with(['supplier', 'invoices.items', 'invoices.documentFile'])->orderBy('id')->get();
$result = [];
foreach ($slips as $slip) {
    $invoices = [];
    foreach ($slip->invoices as $invoice) {
        $document = $invoice->documentFile;
        $exists = $document && Storage::disk($document->disk)->exists($document->path);
        $text = null;
        $error = null;
        if ($exists && $document->disk === 'local') {
            try {
                $pdf = (new Parser)->parseFile(Storage::disk('local')->path($document->path));
                $text = array_map(static fn ($page) => $page->getText(), $pdf->getPages());
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $invoices[] = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'subtotal_amount' => $invoice->subtotal_amount,
            'grand_total_amount' => $invoice->grand_total_amount,
            'tax_addition_amount' => $invoice->tax_addition_amount,
            'tax_deduction_amount' => $invoice->tax_deduction_amount,
            'updated_at' => $invoice->updated_at?->toIso8601String(),
            'items' => $invoice->items->map(fn ($item) => $item->only(['line_number', 'item_name', 'quantity', 'unit_price_amount', 'subtotal_amount']))->all(),
            'document' => $document?->only(['id', 'disk', 'path', 'original_name', 'checksum']),
            'document_exists' => (bool) $exists,
            'pages' => $text,
            'text_error' => $error,
        ];
    }
    $result[] = ['id' => $slip->id, 'status' => $slip->status, 'supplier_id' => $slip->supplier_id, 'supplier' => $slip->supplier?->name, 'invoices' => $invoices];
}
echo json_encode(['captured_at' => now()->toIso8601String(), 'label_status' => 'user_accepted_provisional', 'slips' => $result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
