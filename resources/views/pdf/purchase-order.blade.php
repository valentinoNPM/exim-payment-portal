<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Purchase Order - {{ $purchaseOrder->po_number }}</title>
    <style>
        @page { margin: 11mm 13mm 12mm; }

        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 8.5px; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .numeric { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .center { text-align: center; }

        .document-header { border-bottom: 1.4px solid #1f4e79; margin-bottom: 9px; padding-bottom: 6px; }
        .brand-cell { width: 58%; padding: 0; }
        .document-cell { width: 42%; padding: 0 0 0 12px; }
        .logo { width: 108px; height: auto; margin-bottom: 3px; }
        .company-name { margin: 0 0 3px; color: #111827; font-size: 12.5px; font-weight: 700; letter-spacing: .15px; }
        .company-address { color: #374151; font-size: 8px; line-height: 1.3; }
        .document-title { margin: 0 0 8px; color: #111827; font-size: 20px; font-weight: 700; line-height: 1; text-align: right; }
        .document-meta { margin-left: auto; width: 100%; }
        .document-meta td { padding: 1px 0; }
        .document-meta .meta-label { width: 42%; color: #4b5563; }
        .document-meta .meta-value { font-weight: 700; overflow-wrap: anywhere; }

        .party-table { margin-bottom: 8px; table-layout: fixed; }
        .party-table .party-card { width: 48%; border: .8px solid #64748b; padding: 0; }
        .party-table .party-gap { width: 4%; border: 0; }
        .party-heading { border-bottom: .8px solid #64748b; background: #edf3f8; color: #173a5e; font-size: 8.5px; font-weight: 700; padding: 3px 6px; text-transform: uppercase; letter-spacing: .35px; }
        .party-body { min-height: 66px; padding: 5px 6px 6px; }
        .party-name { min-height: 14px; color: #111827; font-size: 9px; font-weight: 700; }
        .party-address { margin-top: 4px; color: #374151; line-height: 1.35; }

        .items { table-layout: fixed; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { border: .8px solid #64748b; background: #e7eef5; color: #173a5e; font-size: 7.8px; font-weight: 700; line-height: 1.2; padding: 4px 3px; text-align: center; }
        .items td { border: .7px solid #94a3b8; color: #1f2937; font-size: 8px; line-height: 1.28; padding: 4px; }
        .items .item-name { color: #111827; font-weight: 700; }
        .items .item-spec { margin-top: 2px; color: #4b5563; font-size: 7.5px; }
        .items .grand-row td { border-top: 1px solid #475569; background: #f8fafc; color: #111827; font-weight: 700; }

        .below-items { margin-top: 7px; page-break-inside: avoid; }
        .notes-cell { width: 57%; padding: 0 12px 0 0; }
        .summary-cell { width: 43%; padding: 0; }
        .summary td { border: .7px solid #94a3b8; padding: 3px 6px; font-size: 8px; }
        .summary .summary-label { width: 56%; color: #374151; font-weight: 700; }
        .summary .summary-value { width: 44%; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .summary .summary-total td { border-top: 1.2px solid #1f4e79; background: #e7eef5; color: #173a5e; font-size: 9px; font-weight: 700; }

        .approval-wrap { margin-top: 14px; page-break-inside: avoid; table-layout: fixed; }
        .approval-left { width: 57%; padding: 0; }
        .approval-gap { width: 7%; padding: 0; }
        .approval-right { width: 36%; padding: 0; }
        .approval { table-layout: fixed; }
        .approval th { border: .7px solid #94a3b8; background: #f8fafc; color: #334155; font-size: 7.5px; font-weight: 700; line-height: 1.2; padding: 3px 2px; text-align: center; }
        .approval td { height: 43px; border: .7px solid #94a3b8; }
        .approval .name-row td { height: 13px; color: #64748b; font-size: 6.8px; text-align: center; }

        .document-footer { position: fixed; right: 0; bottom: -7mm; left: 0; border-top: .6px solid #cbd5e1; color: #64748b; font-size: 6.8px; padding-top: 2px; }
        .document-footer .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    @php
        use App\Support\CurrencyFormatter;

        $currency = strtoupper($purchaseOrder->currency ?? 'IDR');
        $foreignCurrency = $currency !== 'IDR';
        $money = fn ($value) => CurrencyFormatter::formatFormStateNoDecimals($value, $currency);
        $quantity = fn ($value) => fmod((float) $value, 1.0) === 0.0
            ? number_format((float) $value, 0, ',', '.')
            : rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
        $amountHeading = $foreignCurrency ? 'Jumlah Mata Uang Asing' : 'Jumlah Sebelum Pajak';
        $deductionTaxLabel = $purchaseOrder->taxes
            ->where('calculation_type_snapshot', 'deduction')
            ->map(fn ($tax) => sprintf(
                '%s (%s%%)',
                $tax->tax_name_snapshot ?: $tax->tax_code_snapshot,
                rtrim(rtrim(number_format((float) $tax->rate_snapshot, 4, '.', ''), '0'), '.'),
            ))
            ->filter()
            ->implode(', ');
        $logo = collect(['images/logo-cetak.png', 'images/logo.png'])
            ->first(fn ($path) => file_exists(public_path($path)));
        $defaultAddress = "Dukuh Ngemplak, RT. 006 / RW. 002, Randusari, Teras,\nDusun III, Randusari, Kec. Teras, Kabupaten Boyolali,\nJawa Tengah 57372";
    @endphp

    <div class="document-footer">
        <table><tr><td>PT HANSOLL INDO JAVA - {{ $purchaseOrder->po_number }}</td><td class="numeric">Halaman <span class="page-number"></span></td></tr></table>
    </div>

    <table class="document-header">
        <tr>
            <td class="brand-cell">
                @if ($logo)
                    <img class="logo" src="{{ public_path($logo) }}" alt="Hansoll Indo Java">
                @endif
                <div class="company-name">PT HANSOLL INDO JAVA</div>
                <div class="company-address">
                    Dukuh Ngemplak, RT. 006 / RW. 002, Randusari, Teras,<br>
                    Dusun III, Randusari, Kec. Teras, Kabupaten Boyolali, Jawa Tengah 57372<br>
                    T: 0276 3280401
                </div>
            </td>
            <td class="document-cell">
                <div class="document-title">Purchase Order</div>
                <table class="document-meta">
                    <tr><td class="meta-label">Tanggal</td><td class="meta-value">: {{ $purchaseOrder->po_date?->format('d/m/Y') }}</td></tr>
                    <tr><td class="meta-label">Purchase Order No.</td><td class="meta-value">: {{ $purchaseOrder->po_number }}</td></tr>
                    <tr><td class="meta-label">Lokasi</td><td class="meta-value">: {{ $purchaseOrder->warehouse?->name ?: '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td class="party-card">
                <div class="party-heading">Vendor</div>
                <div class="party-body">
                    <div class="party-name">{{ $purchaseOrder->supplier_name_snapshot ?: ($purchaseOrder->supplier?->name ?: '-') }}</div>
                    @if ($purchaseOrder->supplier_address_snapshot ?: $purchaseOrder->supplier?->address)
                        <div class="party-address">{!! nl2br(e($purchaseOrder->supplier_address_snapshot ?: $purchaseOrder->supplier->address)) !!}</div>
                    @endif
                </div>
            </td>
            <td class="party-gap"></td>
            <td class="party-card">
                <div class="party-heading">Ship To</div>
                <div class="party-body">
                    <div class="party-name">PT HANSOLL INDO JAVA</div>
                    <div class="party-address">{!! nl2br(e($purchaseOrder->delivery_location ?: $defaultAddress)) !!}<br>0276 3280401</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 8%;">Kode Barang</th>
                <th style="width: {{ $foreignCurrency ? '27%' : '34%' }};">Nama Barang [Spec.]</th>
                <th style="width: 7%;">Kuantitas</th>
                <th style="width: 7%;">Satuan</th>
                <th style="width: 11%;">Harga</th>
                <th style="width: 13%;">{{ $amountHeading }}</th>
                @if ($foreignCurrency)
                    <th style="width: 7%;">Tipe Mata Uang Asing</th>
                @endif
                <th style="width: 20%;">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($purchaseOrder->items as $item)
                <tr>
                    <td class="center">{{ $item->item_code_snapshot ?: ($item->item?->source_code ?: ($item->item_code ?: '-')) }}</td>
                    <td>
                        <div class="item-name">{{ $item->item_name_snapshot ?: $item->item_name }}</div>
                        @if ($item->specification ?: $item->specification_snapshot)
                            <div class="item-spec">{{ $item->specification ?: $item->specification_snapshot }}</div>
                        @endif
                    </td>
                    <td class="numeric">{{ $quantity($item->quantity) }}</td>
                    <td class="center">{{ $item->unit_code_snapshot ?: ($item->unit?->code ?: '-') }}</td>
                    <td class="numeric">{{ $money($item->unit_price_amount) }}</td>
                    <td class="numeric">{{ $money($item->subtotal_amount) }}</td>
                    @if ($foreignCurrency)
                        <td class="center">{{ $currency }}</td>
                    @endif
                    <td>{!! nl2br(e($item->notes)) !!}</td>
                </tr>
            @endforeach
            <tr class="grand-row">
                <td colspan="2">Jumlah Keseluruhan</td>
                <td class="numeric">{{ $quantity($purchaseOrder->items->sum('quantity')) }}</td>
                <td></td><td></td>
                <td class="numeric">{{ $money($purchaseOrder->subtotal_amount) }}</td>
                @if ($foreignCurrency)<td></td>@endif
                <td></td>
            </tr>
        </tbody>
    </table>

    <table class="below-items">
        <tr>
            <td class="notes-cell">
            </td>
            <td class="summary-cell">
                <table class="summary">
                    <tr><td class="summary-label">SUB TOTAL</td><td class="summary-value">{{ $money($purchaseOrder->subtotal_amount) }}</td></tr>
                    <tr><td class="summary-label">DISKON</td><td class="summary-value">{{ (float) $purchaseOrder->discount_amount > 0 ? $money($purchaseOrder->discount_amount) : '' }}</td></tr>
                    <tr><td class="summary-label">TAX</td><td class="summary-value">{{ (float) $purchaseOrder->tax_addition_amount > 0 ? $money($purchaseOrder->tax_addition_amount) : '' }}</td></tr>
                    <tr><td class="summary-label">BIAYA KIRIM</td><td class="summary-value">{{ (float) $purchaseOrder->shipping_amount > 0 ? $money($purchaseOrder->shipping_amount) : '' }}</td></tr>
                    <tr><td class="summary-label">{{ $deductionTaxLabel ?: 'PPh' }}</td><td class="summary-value">{{ (float) $purchaseOrder->tax_deduction_amount > 0 ? $money($purchaseOrder->tax_deduction_amount) : '' }}</td></tr>
                    <tr class="summary-total"><td>TOTAL</td><td class="summary-value">{{ $money($purchaseOrder->grand_total_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="approval-wrap">
        <tr>
            <td class="approval-left">
                <table class="approval">
                    <tr><th>PIC</th><th>Area Manager</th><th>Factory Manager</th><th>Direktur</th></tr>
                    <tr><td></td><td></td><td></td><td></td></tr>
                    <tr class="name-row"><td>{{ $purchaseOrder->pic_name }}</td><td></td><td></td><td></td></tr>
                </table>
            </td>
            <td class="approval-gap"></td>
            <td class="approval-right">
                <table class="approval">
                    <tr><th>GA</th><th>Manager</th><th>Direktur</th></tr>
                    <tr><td></td><td></td><td></td></tr>
                    <tr class="name-row"><td></td><td></td><td></td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
