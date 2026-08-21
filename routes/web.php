<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/document-files/{documentFile}/view', function (\App\Models\DocumentFile $documentFile) {
    if (!\Illuminate\Support\Facades\Storage::disk($documentFile->disk)->exists($documentFile->path)) {
        abort(404);
    }
    return response()->file(\Illuminate\Support\Facades\Storage::disk($documentFile->disk)->path($documentFile->path), [
        'Content-Type' => $documentFile->mime_type,
        'Content-Disposition' => 'inline; filename="' . $documentFile->original_name . '"',
    ]);
})->name('document-files.view')->middleware(['auth']);

