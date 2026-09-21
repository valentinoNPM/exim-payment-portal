<x-filament-panels::page>
    <div class="erp-export-finance">
        <div class="erp-export-finance__header">
            <div>
                <div class="erp-export-finance__eyebrow">Finance Operations</div>
                <p>Generate one ERP journal file from one verified payment slip.</p>
            </div>
            <div class="erp-export-finance__status">ERP Ready</div>
        </div>

        <x-filament::tabs label="Export queue" class="erp-export-finance__tabs">
            <x-filament::tabs.item :active="$activeTab === 'ready'" wire:click="$set('activeTab', 'ready')">Ready to Export</x-filament::tabs.item>
            <x-filament::tabs.item :active="$activeTab === 'history'" wire:click="$set('activeTab', 'history')">Export History</x-filament::tabs.item>
        </x-filament::tabs>

        <div class="erp-export-finance__table">
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
