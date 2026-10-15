<?php

namespace App\Services\PaymentSlips;

use App\Models\PaymentSlip;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
use App\Services\InvoiceExtractionDataMapper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExtractInvoicesIntoPaymentSlip
{
    public function __construct(
        private GeminiInvoiceExtractor $extractor,
        private DocumentFileRegistrar $documentFiles,
        private InvoiceExtractionDataMapper $mapper,
    ) {}

    /**
     * @param  list<UploadedFile>  $uploads
     * @return array{successful_files: int, failed_files: int, invoice_count: int, item_count: int}
     */
    public function execute(PaymentSlip $paymentSlip, array $uploads): array
    {
        $paths = [];
        $names = [];
        foreach ($uploads as $upload) {
            $path = $upload->store('invoice-uploads', 'local');
            $paths[] = $path;
            $names[$path] = $upload->getClientOriginalName();
        }

        $files = array_map(fn (string $path): array => [
            'path' => Storage::disk('local')->path($path),
            'original_name' => $names[$path],
        ], $paths);
        $report = $this->extractor->extractWithReport($files, $paymentSlip->supplier?->name);
        $successfulFiles = 0;
        $invoiceCount = 0;
        $itemCount = 0;

        foreach ($report['successful'] as $successful) {
            $storedPath = collect($paths)->first(
                fn (string $path): bool => Storage::disk('local')->path($path) === $successful['path'],
            );
            if (! $storedPath) {
                $report['failed'][] = $successful;

                continue;
            }

            try {
                $documentIds = $this->documentFiles->registerLocalUploads([$storedPath], $names);
                $mapped = $this->mapper->toRepeaterState(
                    $successful['invoices'],
                    $documentIds,
                    preferBaseAmount: $paymentSlip->transaction_type === PaymentSlip::TYPE_IMPORT
                        || ($paymentSlip->transaction_type === PaymentSlip::TYPE_EXPORT && $paymentSlip->usesItemizedTaxes()),
                );

                DB::transaction(function () use ($paymentSlip, $mapped): void {
                    foreach ($mapped as $invoiceData) {
                        $items = $invoiceData['items'];
                        unset($invoiceData['items']);
                        $invoice = $paymentSlip->invoices()->create($invoiceData);
                        foreach (array_values($items) as $index => $itemData) {
                            $invoice->items()->create([...$itemData, 'line_number' => $index + 1]);
                        }
                        $invoice->recalculateTotals();
                    }
                });
            } catch (Throwable) {
                $report['failed'][] = $successful;

                continue;
            }

            $successfulFiles++;
            $invoiceCount += count($mapped);
            $itemCount += array_sum(array_map(static fn (array $invoice): int => count($invoice['items']), $mapped));
        }

        $paymentSlip->recalculateTotals();

        return [
            'successful_files' => $successfulFiles,
            'failed_files' => count($report['failed']),
            'invoice_count' => $invoiceCount,
            'item_count' => $itemCount,
        ];
    }
}
