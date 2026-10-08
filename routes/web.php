<?php

use App\Http\Controllers\CheckerEditPaymentSlipController;
use App\Http\Controllers\DownloadErpExportController;
use App\Http\Controllers\PreviewPaymentSlipPdfController;
use App\Http\Controllers\PreviewPurchaseOrderPdfController;
use App\Http\Controllers\PurchaseOrderEditorController;
use App\Models\DocumentFile;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/erp-exports/{batch}/download', DownloadErpExportController::class)
    ->middleware('auth')->name('erp-exports.download');

Route::get('/payment-slips/{paymentSlip}/pdf', PreviewPaymentSlipPdfController::class)
    ->middleware('auth')->name('payment-slips.pdf.preview');

Route::get('/purchase-orders/{purchaseOrder}/pdf', PreviewPurchaseOrderPdfController::class)
    ->middleware(FilamentAuthenticate::class)->name('purchase-orders.pdf.preview');

Route::prefix('admin/purchase-orders')
    ->middleware(FilamentAuthenticate::class)
    ->group(function (): void {
        Route::post('/', [PurchaseOrderEditorController::class, 'store'])
            ->name('purchase-orders.editor.store');
        Route::put('/{purchaseOrder}', [PurchaseOrderEditorController::class, 'update'])
            ->name('purchase-orders.editor.update');
        Route::get('/editor/items', [PurchaseOrderEditorController::class, 'items'])
            ->name('purchase-orders.editor.items');
        Route::get('/editor/number-preview', [PurchaseOrderEditorController::class, 'numberPreview'])
            ->name('purchase-orders.editor.number-preview');
    });

Route::get('/payment-slips/{paymentSlip}/checker-edit', [CheckerEditPaymentSlipController::class, 'show'])
    ->middleware('auth')->name('payment-slips.checker-edit');
Route::post('/payment-slips/{paymentSlip}/checker-edit', [CheckerEditPaymentSlipController::class, 'update'])
    ->middleware('auth')->name('payment-slips.checker-edit.update');

Route::redirect('/', '/admin');

Route::get('/document-files/{documentFile}/view', function (DocumentFile $documentFile) {
    if (! Storage::disk($documentFile->disk)->exists($documentFile->path)) {
        abort(404);
    }

    return response()->file(Storage::disk($documentFile->disk)->path($documentFile->path), [
        'Content-Type' => $documentFile->mime_type,
        'Content-Disposition' => 'inline; filename="'.$documentFile->original_name.'"',
    ]);
})->name('document-files.view')->middleware(['auth']);
