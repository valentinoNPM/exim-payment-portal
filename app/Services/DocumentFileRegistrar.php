<?php

namespace App\Services;

use App\Models\DocumentFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DocumentFileRegistrar
{
    public function registerLocalUploads(array $paths, array $originalNames = []): array
    {
        $documentIds = [];

        foreach ($paths as $path) {
            if (! Storage::disk('local')->exists($path)) {
                throw new RuntimeException("Uploaded PDF does not exist: {$path}");
            }

            $fullPath = Storage::disk('local')->path($path);
            $checksum = md5_file($fullPath);

            if ($checksum === false) {
                throw new RuntimeException("Unable to calculate PDF checksum: {$path}");
            }

            $document = DocumentFile::query()
                ->where('disk', 'local')
                ->where('checksum', $checksum)
                ->first();

            if (! $document) {
                $document = DocumentFile::create([
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $originalNames[$path] ?? basename($path),
                    'mime_type' => Storage::disk('local')->mimeType($path) ?: 'application/pdf',
                    'size_bytes' => Storage::disk('local')->size($path),
                    'checksum' => $checksum,
                    'uploaded_at' => now(),
                ]);
            }

            $documentIds[] = $document->id;
        }

        return $documentIds;
    }
}
