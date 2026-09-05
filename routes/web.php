<?php

use App\Http\Controllers\DownloadErpExportController;
use App\Models\DocumentFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/erp-exports/{batch}/download', DownloadErpExportController::class)
    ->middleware('auth')->name('erp-exports.download');

Route::get('/', function () {
    return view('welcome');
});

Route::get('/document-files/{documentFile}/view', function (DocumentFile $documentFile) {
    if (! Storage::disk($documentFile->disk)->exists($documentFile->path)) {
        abort(404);
    }

    return response()->file(Storage::disk($documentFile->disk)->path($documentFile->path), [
        'Content-Type' => $documentFile->mime_type,
        'Content-Disposition' => 'inline; filename="'.$documentFile->original_name.'"',
    ]);
})->name('document-files.view')->middleware(['auth']);
