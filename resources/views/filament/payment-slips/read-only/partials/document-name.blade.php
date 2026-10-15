@if ($document)
    <div class="payment-slip-detail__document-name">
        <span>File</span>
        <button
            type="button"
            title="Tampilkan {{ $document->original_name }}"
            wire:click="setActivePdf({{ (int) $invoice->document_file_id }})"
        >{{ $document->original_name }}</button>
        @if (! $documentIsAvailable)
            <small>Berkas tidak tersedia (ID {{ $document->id }})</small>
        @endif
    </div>
@endif
