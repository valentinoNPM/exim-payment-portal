<x-filament-panels::page>
    <div class="payment-slip-workspace" x-data="{ activePanel: 'form' }">
        <div class="payment-slip-workspace__tabs" role="tablist" aria-label="Payment slip workspace">
            <button
                type="button"
                role="tab"
                :aria-selected="activePanel === 'form'"
                :class="{ 'is-active': activePanel === 'form' }"
                @click="activePanel = 'form'"
            >
                Form
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="activePanel === 'pdf'"
                :class="{ 'is-active': activePanel === 'pdf' }"
                @click="activePanel = 'pdf'"
            >
                PDF
            </button>
        </div>

        <div class="payment-slip-workspace__layout">
            <section
                class="payment-slip-workspace__form"
                :class="{ 'is-hidden-mobile': activePanel !== 'form' }"
                aria-label="Payment slip form"
            >
                {{ $this->content }}
            </section>

            <section
                class="payment-slip-workspace__pdf"
                :class="{ 'is-hidden-mobile': activePanel !== 'pdf' }"
                aria-label="Invoice PDF viewer"
            >
                <header class="payment-slip-workspace__pdf-header">
                    <span>Invoice PDF Viewer</span>
                    @if($activePdfUrl)
                        <a href="{{ $activePdfUrl }}" target="_blank" rel="noopener noreferrer">Open in new tab</a>
                    @endif
                </header>

                <div class="payment-slip-workspace__pdf-body">
                    @if($activePdfUrl)
                        <iframe src="{{ $activePdfUrl }}" title="Invoice PDF"></iframe>
                    @else
                        <div class="payment-slip-workspace__empty">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <p>No invoice PDF selected. Click "Preview PDF" on an invoice row to view it.</p>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
