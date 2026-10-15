@php($money = fn ($amount) => \App\Support\CurrencyFormatter::format($amount, $po->currency))
<section class="ps-editor__section payment-slip-detail">
    <h2>Sumber PO: {{ $po->po_number }}</h2>
    <p>Supplier: {{ $po->supplier_name_snapshot ?: $po->supplier?->name }} · Mata uang: {{ $po->currency }}</p>
    <div class="payment-slip-detail__scroll">
        <table class="payment-slip-detail__table">
            <thead><tr><th>Nama Barang / Spesifikasi</th><th class="payment-slip-detail__number">Kuantitas</th><th>Satuan</th><th class="payment-slip-detail__number">Harga</th><th class="payment-slip-detail__number">Total sebelum penyesuaian</th></tr></thead>
            <tbody>@foreach($po->items as $item)<tr>
                <td>{{ $item->item_name_snapshot ?: $item->item_name }}@if(filled($item->specification)) [{{ $item->specification }}]@endif</td>
                <td class="payment-slip-detail__number">{{ rtrim(rtrim($item->quantity, '0'), '.') }}</td>
                <td>{{ $item->unit?->name ?? '—' }}</td>
                <td class="payment-slip-detail__number">{{ $money($item->unit_price_amount) }}</td>
                <td class="payment-slip-detail__number">{{ $money($item->total_amount) }}</td>
            </tr>@endforeach</tbody>
        </table>
    </div>
    <dl class="payment-slip-detail__summary">
        @foreach(['subtotal_amount' => 'Subtotal', 'tax_addition_amount' => 'PPN (+)', 'tax_deduction_amount' => 'PPh (−)', 'discount_amount' => 'Diskon (−)', 'shipping_amount' => 'Ongkos Kirim (+)', 'grand_total_amount' => 'Total'] as $field => $label)
            <div><dt>{{ $label }}</dt><dd>{{ $money($po->{$field}) }}</dd></div>
        @endforeach
    </dl>
</section>
