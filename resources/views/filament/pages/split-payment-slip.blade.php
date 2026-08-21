<x-filament-panels::page>
    <div style="display: flex; gap: 20px; align-items: stretch; min-height: 700px;">
        <!-- Right Panel: PDF Viewer (50% width) -->
        <div style="order: 2; flex: 1; border: 1px solid #d1d5db; border-radius: 0.5rem; overflow: hidden; background-color: #f3f4f6; display: flex; flex-direction: column; min-height: 600px;">
            <div style="background-color: #e5e7eb; padding: 10px 15px; font-weight: 600; border-bottom: 1px solid #d1d5db; display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                <span style="color: #374151;">Invoice PDF Viewer</span>
                @if($activePdfUrl)
                    <a href="{{ $activePdfUrl }}" target="_blank" style="font-size: 11px; color: #3b82f6; text-decoration: underline;">Open in new tab</a>
                @endif
            </div>
            
            <div style="flex: 1; display: flex; align-items: center; justify-content: center; position: relative;">
                @if($activePdfUrl)
                    <iframe src="{{ $activePdfUrl }}" style="width: 100%; height: 100%; position: absolute; top: 0; left: 0; border: none;"></iframe>
                @else
                    <div style="color: #6b7280; font-size: 13px; text-align: center; padding: 20px; margin: auto;">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" style="height: 48px; width: 48px; margin: 0 auto 10px; color: #9ca3af;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        No invoice PDF selected. Click "Preview PDF" on an invoice row to view it.
                    </div>
                @endif
            </div>
        </div>

        <!-- Left Panel: Form (50% width) -->
        <div style="order: 1; flex: 1; background-color: #ffffff; border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 20px; overflow-y: auto; max-height: calc(100vh - 180px);">
            {{ $this->content }}
        </div>
    </div>
</x-filament-panels::page>
