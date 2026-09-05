<?php

namespace Tests\Unit;

use App\Models\DocumentFile;
use App\Services\DocumentFileRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentFileRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_document_metadata_for_identical_pdf_content(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoice-uploads/first.pdf', 'same-pdf-content');
        Storage::disk('local')->put('invoice-uploads/second.pdf', 'same-pdf-content');

        $registrar = new DocumentFileRegistrar;
        $firstIds = $registrar->registerLocalUploads(['invoice-uploads/first.pdf']);
        $secondIds = $registrar->registerLocalUploads(['invoice-uploads/second.pdf']);

        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(1, DocumentFile::query()->count());
    }

    public function test_it_preserves_the_original_upload_name(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoice-uploads/random-name.pdf', 'pdf-content');

        (new DocumentFileRegistrar)->registerLocalUploads(
            ['invoice-uploads/random-name.pdf'],
            ['invoice-uploads/random-name.pdf' => 'Vendor Invoice 001.pdf'],
        );

        $this->assertSame('Vendor Invoice 001.pdf', DocumentFile::query()->sole()->original_name);
    }
}
