<x-filament-panels::page>
    <p>Generate one ERP journal file from one verified payment slip.</p>
    <x-filament::tabs label="Export queue">
        <x-filament::tabs.item :active="$activeTab === 'ready'" wire:click="$set('activeTab', 'ready')">Ready to Export</x-filament::tabs.item>
        <x-filament::tabs.item :active="$activeTab === 'history'" wire:click="$set('activeTab', 'history')">Export History</x-filament::tabs.item>
    </x-filament::tabs>
    {{ $this->table }}
</x-filament-panels::page>
