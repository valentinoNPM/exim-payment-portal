<?php

namespace App\Http\Controllers;

use App\Models\ErpExportBatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadErpExportController extends Controller
{
    public function __invoke(ErpExportBatch $batch): StreamedResponse
    {
        abort_unless(auth()->user()?->hasRole('checker'), 403);
        abort_unless($batch->file_path && Storage::disk('local')->exists($batch->file_path), 404);

        return Storage::disk('local')->download($batch->file_path, basename($batch->file_path));
    }
}
