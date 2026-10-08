<x-filament-panels::page>
    @php
        $record->loadMissing(['supplier', 'warehouse', 'items.unit', 'taxes', 'invoices.paymentSlip']);
        $money = fn ($value) => \App\Support\CurrencyFormatter::format($value, $record->currency);
        $jumlah = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
        $isi = fn ($value) => filled($value) ? $value : '—';

        $identitas = [
            'Nomor PO' => $record->po_number,
            'Tanggal PO' => $record->po_date?->translatedFormat('d F Y'),
            'Vendor / Supplier' => $record->supplier?->name,
            'PIC' => $record->pic_name,
            'Mata Uang' => $record->currency,
            'Pajak Dokumen' => $record->taxes->first()?->tax_name_snapshot ?? 'Tanpa pajak',
            'Status Informasi' => \App\Models\PurchaseOrder::STATUS_LABELS[$record->status] ?? $record->status,
            'Tanggal Pengiriman' => $record->delivery_date?->translatedFormat('d F Y'),
            'Gudang' => $record->warehouse
                ? $record->warehouse->name.' ('.$record->warehouse->source_code.')'
                : null,
        ];

    @endphp

    <div class="po-view">
        <x-filament::section heading="Data Purchase Order">
            <dl class="po-view__grid">
                @foreach ($identitas as $label => $value)
                    <div class="po-view__item">
                        <dt class="po-view__label">{{ $label }}</dt>
                        <dd class="po-view__value">{{ $isi($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Item Purchase Order
                <span class="po-view__count">{{ $record->items->count() }} baris</span>
            </x-slot>

            @if ($record->items->isEmpty())
                <p class="po-view__empty">Belum ada item pada PO ini.</p>
            @else
                <div class="po-view__scroll">
                    <table class="po-view__table">
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Item</th>
                                <th>Spesifikasi</th>
                                <th>Keterangan</th>
                                <th class="po-view__num">Kuantitas</th>
                                <th>Satuan</th>
                                <th class="po-view__num">Harga</th>
                                <th class="po-view__num">Subtotal</th>
                                <th class="po-view__num">Dasar Pajak</th>
                                <th class="po-view__num">Nilai Pajak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($record->items as $item)
                                <tr>
                                    <td class="po-view__code">{{ $isi($item->item_code_snapshot ?: $item->item_code) }}</td>
                                    <td>{{ $isi($item->item_name_snapshot ?: $item->item_name) }}</td>
                                    <td class="po-view__muted">{{ $isi($item->specification ?: $item->specification_snapshot) }}</td>
                                    <td class="po-view__muted po-view__note">{{ $isi($item->notes) }}</td>
                                    <td class="po-view__num">{{ $jumlah($item->quantity) }}</td>
                                    <td>{{ $isi($item->unit_code_snapshot ?: $item->unit?->code) }}</td>
                                    <td class="po-view__num">{{ $money($item->unit_price_amount) }}</td>
                                    <td class="po-view__num">{{ $money($item->subtotal_amount) }}</td>
                                    <td class="po-view__num">{{ $money($item->subtotal_amount) }}</td>
                                    <td class="po-view__num">{{ $money($item->tax_amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Invoice Tertaut">
            @include('filament.purchase-orders.linked-invoices', ['invoices' => $record->invoices])
        </x-filament::section>

        <x-filament::section heading="Ringkasan">
            <dl class="po-view__summary">
                @foreach (['Subtotal' => $record->subtotal_amount, 'PPN (Penambahan)' => $record->tax_addition_amount, 'PPh (Potongan)' => $record->tax_deduction_amount, 'Diskon' => $record->discount_amount, 'Biaya Kirim' => $record->shipping_amount, 'Total PO' => $record->grand_total_amount] as $label => $value)
                    <div class="po-view__summary-row {{ $label === 'Total PO' ? 'po-view__summary-row--total' : '' }}">
                        <dt>{{ $label }}</dt>
                        <dd>{{ $money($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    </div>
</x-filament-panels::page>
