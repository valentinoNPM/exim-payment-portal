<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\PaymentSlip;
use App\Models\Supplier;
use App\Services\DocumentFileRegistrar;
use App\Services\GeminiInvoiceExtractor;
use App\Services\InvoiceExtractionDataMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PaymentSlipInvoiceExtractionPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        string $type,
        GeminiInvoiceExtractor $extractor,
        DocumentFileRegistrar $registrar,
        InvoiceExtractionDataMapper $mapper,
    ): JsonResponse {
        abort_unless(in_array($type, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true), 404);
        $record = $request->filled('payment_slip_id')
            ? PaymentSlip::findOrFail($request->input('payment_slip_id'))
            : null;
        abort_unless($record
            ? $record->transaction_type === $type && $record->status === 'draft' && PaymentSlipResource::canEdit($record)
            : PaymentSlipResource::canCreateType(auth()->user(), $type), 403);

        $validated = $request->validate([
            'raw_pdf_files' => ['required', 'array', 'min:1'],
            'raw_pdf_files.*' => ['required', 'file', 'mimetypes:application/pdf', 'max:102400'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);

        $files = [];
        foreach ($validated['raw_pdf_files'] as $uploaded) {
            $path = $uploaded->store('invoice-uploads', 'local');
            $files[$path] = $uploaded->getClientOriginalName();
        }

        $report = $extractor->extractWithReport(
            array_map(fn (string $path, string $name): array => [
                'path' => Storage::disk('local')->path($path),
                'original_name' => $name,
            ], array_keys($files), array_values($files)),
            filled($validated['supplier_id'] ?? null)
                ? Supplier::query()->whereKey($validated['supplier_id'])->value('name')
                : null,
        );

        $invoices = [];
        $failures = $report['failed'] ?? [];
        foreach ($report['successful'] ?? [] as $successful) {
            $path = collect(array_keys($files))->first(fn (string $candidate): bool => Storage::disk('local')->path($candidate) === $successful['path']);
            if (! $path) {
                continue;
            }

            try {
                $documentId = $registrar->registerLocalUploads([$path], $files)[0];
                $mapped = $mapper->toRepeaterState($successful['invoices'], [$documentId], preferBaseAmount: true);
                foreach ($mapped as $row) {
                    $row['tax_calculation_mode'] = PaymentSlip::TAX_MODE_ITEMIZED;
                    $row['document_name'] = $files[$path];
                    $row['document_url'] = route('document-files.view', $documentId);
                    $row['items'] = array_values($row['items']);
                    $invoices[] = $row;
                }
                $allowed = session('payment_slip_editor_document_ids', []);
                $allowed[] = $documentId;
                session(['payment_slip_editor_document_ids' => array_values(array_unique($allowed))]);
            } catch (Throwable $exception) {
                report($exception);
                $failures[] = ['original_name' => $files[$path], 'message' => 'Registrasi dokumen gagal.'];
            }
        }

        if ($invoices === []) {
            return response()->json(['message' => 'Ekstraksi AI gagal. Silakan input invoice secara manual.', 'failed' => $failures], 422);
        }

        return response()->json([
            'invoices' => $invoices,
            'message' => count($invoices).' invoice berhasil diekstrak. Periksa semua data sebelum menyimpan.',
            'failed' => $failures,
        ]);
    }
}
