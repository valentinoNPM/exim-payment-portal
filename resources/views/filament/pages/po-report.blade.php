<x-filament-panels::page>
    <style>
        .laporan-po-kepala {
            border-bottom: 2px solid #111827;
            padding-bottom: .5rem;
            margin-bottom: .75rem;
        }
        .laporan-po-kepala h2 { font-size: 1.05rem; font-weight: 700; margin: 0; }
        .laporan-po-kepala p { margin: .15rem 0 0; font-size: .8rem; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header, nav, header,
            .fi-ta-header-toolbar, .fi-ta-actions, .fi-pagination,
            .fi-ac, .fi-dropdown { display: none !important; }
            .fi-main, .fi-page { padding: 0 !important; margin: 0 !important; }
            .fi-body { background: #fff !important; }
            .fi-ta-table { font-size: 10pt; }
        }
    </style>

    <div class="laporan-po-kepala">
        <h2>Purchase Order Status — Laporan PO</h2>
        <p>Nama Perusahaan: {{ $this->namaPerusahaan() }}</p>
        <p>Periode: {{ $this->labelPeriode() }}</p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
