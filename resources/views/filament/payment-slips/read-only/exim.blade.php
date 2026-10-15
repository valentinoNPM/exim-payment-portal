@php
    $money = fn ($value) => \App\Support\CurrencyFormatter::format($value, 'IDR');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    $display = fn ($value) => filled($value) ? $value : '—';
    $showSlipBuyer = $slip->transaction_type === \App\Models\PaymentSlip::TYPE_EXPORT && ! $slip->usesItemizedTaxes();
    $transaction = [
        'Slip Number' => $slip->slip_number,
        'Nomor Tanda Terima Invoice' => $slip->invoice_receipt_number,
        'Supplier' => $slip->supplier?->name,
        'Transaction type' => \App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$slip->transaction_type] ?? $slip->transaction_type,
        ...($showSlipBuyer ? ['Buyer' => $slip->buyer?->name] : []),
        'Nama' => $slip->creator?->name,
        'Division' => $slip->creator?->division?->name,
        'Tanggal' => $slip->created_at?->format('d/m/Y, H:i'),
    ];
@endphp

<div class="payment-slip-detail">
    @include('filament.components.status-steps', ['getRecord' => fn () => $slip])

    <x-filament::section heading="Transaction Details">
        <dl class="payment-slip-detail__grid">
            @foreach ($transaction as $label => $value)
                <div class="payment-slip-detail__field">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $display($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section heading="Invoices">
        <div class="payment-slip-detail__invoices">
            @forelse ($slip->invoices as $invoice)
                @php
                    $itemized = $invoice->usesItemizedTaxes();
                    $showItemVat = $slip->transaction_type === \App\Models\PaymentSlip::TYPE_IMPORT || $itemized;
                    $invoiceFields = [
                        'Buyer' => $invoice->buyer?->name,
                        'Tanggal invoice' => $invoice->invoice_date?->translatedFormat('d F Y'),
                        'VAT Invoice No. Utama' => $invoice->vat_invoice_number,
                    ];
                    $ppnLabel = $itemized ? 'Penambahan Pajak (PPN)' : ($invoice->ppnTax?->name ?: 'Penambahan Pajak (PPN)');
                    $pphLabel = $itemized ? 'Total PPh' : ($invoice->pphTax?->name ?: 'Pengurangan Pajak (PPh)');
                    $document = $invoice->documentFile;
                    $documentIsAvailable = $document
                        && \Illuminate\Support\Facades\Storage::disk($document->disk)->exists($document->path);
                @endphp

                <article class="payment-slip-detail__invoice">
                    <header class="payment-slip-detail__invoice-header">
                        <h3>
                            <span>Invoice {{ $loop->iteration }}</span>
                            <strong>{{ $display($invoice->invoice_number) }}</strong>
                        </h3>

                    @include('filament.payment-slips.read-only.partials.document')
                    </header>

                    <div class="payment-slip-detail__invoice-info">
                            <dl class="payment-slip-detail__invoice-grid">
                            @foreach ($invoiceFields as $label => $value)
                            <div class="payment-slip-detail__field">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $display($value) }}</dd>
                            </div>
                            @endforeach
                            </dl>
                            @include('filament.payment-slips.read-only.partials.document-name')
                    </div>

                    <div class="payment-slip-detail__items">
                        <h3>Items</h3>
                        <div class="payment-slip-detail__scroll">
                            <table class="payment-slip-detail__table">
                                <thead>
                                    <tr>
                                        <th>Item name</th>
                                        <th class="payment-slip-detail__number">Qty</th>
                                        <th class="payment-slip-detail__number">Unit price</th>
                                            <th>Supplier Pendukung</th>
                                        @if ($showItemVat)
                                            <th>VAT Invoice No. (Item)</th>
                                        @endif
                                        @if ($itemized)
                                            <th>PPN</th>
                                            <th class="payment-slip-detail__number">PPN Nominal</th>
                                            <th>PPh</th>
                                            <th class="payment-slip-detail__number">PPh Nominal</th>
                                            <th class="payment-slip-detail__number">Amount Dibayar</th>
                                        @endif
                                        @if (auth()->user()?->hasRole('checker'))
                                            <th>COA</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($invoice->items as $item)
                                        <tr>
                                            <td>{{ $display($item->item_name) }}</td>
                                            <td class="payment-slip-detail__number">{{ $quantity($item->quantity) }}</td>
                                            <td class="payment-slip-detail__number">{{ $money($item->unit_price_amount) }}</td>
                                                <td>{{ $display($item->source_supplier_name) }}</td>
                                            @if ($showItemVat)
                                                <td>{{ $display($item->vat_invoice_number) }}</td>
                                            @endif
                                            @if ($itemized)
                                                <td>{{ $display($item->ppnTax?->name) }}</td>
                                                <td class="payment-slip-detail__number">{{ $money($item->tax_addition_amount) }}</td>
                                                <td>{{ $display($item->pphTax?->name) }}</td>
                                                <td class="payment-slip-detail__number">{{ $money($item->tax_deduction_amount) }}</td>
                                                <td class="payment-slip-detail__number">{{ $money($item->net_amount) }}</td>
                                            @endif
                                            @if (auth()->user()?->hasRole('checker'))
                                                <td>{{ $display($item->chartOfAccount ? $item->chartOfAccount->code.' - '.$item->chartOfAccount->name : null) }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <dl class="payment-slip-detail__summary">
                        <div><dt>Subtotal</dt><dd>{{ $money($invoice->subtotal_amount) }}</dd></div>
                        <div><dt>{{ $ppnLabel }}</dt><dd>+ {{ $money($invoice->tax_addition_amount) }}</dd></div>
                        <div><dt>{{ $pphLabel }}</dt><dd>&minus; {{ $money($invoice->tax_deduction_amount) }}</dd></div>
                        <div class="payment-slip-detail__summary-total"><dt>Amount Dibayar</dt><dd>{{ $money($invoice->grand_total_amount) }}</dd></div>
                    </dl>
                </article>
            @empty
                <p class="payment-slip-detail__empty">Belum ada invoice pada slip ini.</p>
            @endforelse
        </div>
    </x-filament::section>

    @include('filament.payment-slips.read-only.partials.overview')
</div>
