@if ($document)
    <div class="payment-slip-detail__document">
        <x-filament::button
            type="button"
            size="sm"
            color="gray"
            icon="heroicon-m-eye"
            title="Preview PDF"
            aria-label="Preview PDF {{ $document->original_name }}"
            wire:click="setActivePdf({{ (int) $invoice->document_file_id }})"
        >
            Preview PDF
        </x-filament::button>
    </div>
@endif
