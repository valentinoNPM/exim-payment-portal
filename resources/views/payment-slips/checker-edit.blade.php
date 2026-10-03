<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Check {{ $paymentSlip->slip_number }} — EXIM Payment Portal</title>
    <link rel="stylesheet" href="{{ asset('css/checker-edit.css') }}">
</head>
<body>
    <main class="checker-page">
        <nav class="checker-nav" aria-label="Page navigation">
            <a href="{{ \App\Filament\Resources\PaymentSlips\PaymentSlipResource::getUrl('index') }}" class="checker-back">← Payment Slips</a>
            <div class="checker-nav__actions">
                <a href="{{ route('payment-slips.pdf.preview', $paymentSlip) }}" target="_blank" rel="noopener noreferrer" class="checker-button checker-button--secondary">Preview Payment Slip PDF</a>
            </div>
        </nav>

        <header class="checker-header">
            <div>
                <p class="checker-eyebrow">Accounting verification</p>
                <h1>{{ $paymentSlip->slip_number }}</h1>
                <p>Review submitted data and complete only the accounting fields below.</p>
            </div>
            <span class="checker-status">Submitted</span>
        </header>

        @if (session('status'))
            <div class="checker-alert checker-alert--success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="checker-alert checker-alert--error" role="alert">
                <strong>Changes were not saved.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="checker-summary" aria-label="Payment slip summary">
            <dl>
                <div><dt>Type</dt><dd>{{ \App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$paymentSlip->transaction_type] ?? ucfirst($paymentSlip->transaction_type) }}</dd></div>
                <div><dt>Supplier</dt><dd>{{ $paymentSlip->supplier?->name ?? '—' }}</dd></div>
                <div><dt>Buyer</dt><dd>{{ $paymentSlip->buyer?->name ?? 'Per invoice' }}</dd></div>
                <div><dt>Currency</dt><dd>{{ $paymentSlip->currency ?? 'IDR' }}</dd></div>
                <div><dt>Created by</dt><dd>{{ $paymentSlip->creator?->name ?? '—' }} · {{ $paymentSlip->creator?->division?->name ?? '—' }}</dd></div>
                <div><dt>Total</dt><dd class="checker-money">{{ \App\Support\CurrencyFormatter::format($paymentSlip->grand_total_amount, $paymentSlip->currency ?? 'IDR') }}</dd></div>
            </dl>
        </section>

        <form method="POST" action="{{ route('payment-slips.checker-edit.update', $paymentSlip) }}">
            @csrf

            <div class="checker-invoices">
                @foreach ($paymentSlip->invoices as $invoice)
                    @php($showItemVat = \App\Support\CheckerEditableFields::canEditItemVat($paymentSlip, $invoice))
                    <section class="checker-invoice" aria-labelledby="invoice-{{ $invoice->id }}-title">
                        <header class="checker-invoice__header">
                            <div>
                                <p class="checker-eyebrow">Invoice {{ $loop->iteration }}</p>
                                <h2 id="invoice-{{ $invoice->id }}-title">{{ $invoice->invoice_number }}</h2>
                                <p>{{ $invoice->invoice_date?->format('d M Y') }} · {{ $invoice->buyer?->name ?? $paymentSlip->buyer?->name ?? 'Buyer unavailable' }}</p>
                            </div>
                            @if ($invoice->documentFile)
                                <a href="{{ route('document-files.view', $invoice->documentFile) }}" target="_blank" rel="noopener noreferrer" class="checker-document-link">Open invoice PDF ↗</a>
                            @endif
                        </header>

                        <div class="checker-invoice__totals" aria-label="Invoice totals">
                            <div><span>Subtotal</span><strong>{{ \App\Support\CurrencyFormatter::format($invoice->subtotal_amount, $paymentSlip->currency ?? 'IDR') }}</strong></div>
                            <div><span>PPN</span><strong>{{ \App\Support\CurrencyFormatter::format($invoice->tax_addition_amount, $paymentSlip->currency ?? 'IDR') }}</strong></div>
                            <div><span>PPh</span><strong>{{ \App\Support\CurrencyFormatter::format($invoice->tax_deduction_amount, $paymentSlip->currency ?? 'IDR') }}</strong></div>
                            <div><span>Grand total</span><strong>{{ \App\Support\CurrencyFormatter::format($invoice->grand_total_amount, $paymentSlip->currency ?? 'IDR') }}</strong></div>
                        </div>

                        <div class="checker-field checker-field--invoice">
                            <label for="invoice-{{ $invoice->id }}-vat">VAT Invoice No. Utama</label>
                            <input
                                id="invoice-{{ $invoice->id }}-vat"
                                name="invoices[{{ $invoice->id }}][vat_invoice_number]"
                                value="{{ old("invoices.{$invoice->id}.vat_invoice_number", $invoice->vat_invoice_number) }}"
                                maxlength="255"
                                autocomplete="off"
                            >
                        </div>

                        <div class="checker-table-wrap">
                            <table class="checker-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Line</th>
                                        <th scope="col">Item</th>
                                        <th scope="col" class="checker-number">Qty</th>
                                        <th scope="col" class="checker-number">Unit price</th>
                                        <th scope="col" class="checker-number">Subtotal</th>
                                        <th scope="col" class="checker-number">PPN</th>
                                        <th scope="col" class="checker-number">PPh</th>
                                        <th scope="col" class="checker-number">Net</th>
                                        @if ($showItemVat)<th scope="col">VAT Invoice No. (Item)</th>@endif
                                        <th scope="col">COA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($invoice->items as $item)
                                        <tr>
                                            <td>{{ $item->line_number }}</td>
                                            <td>
                                                <strong>{{ $item->item_name }}</strong>
                                                @if ($item->source_supplier_name)<small>{{ $item->source_supplier_name }}</small>@endif
                                            </td>
                                            <td class="checker-number">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ','), '0'), '.') }}</td>
                                            <td class="checker-number">{{ \App\Support\CurrencyFormatter::format($item->unit_price_amount, $paymentSlip->currency ?? 'IDR') }}</td>
                                            <td class="checker-number">{{ \App\Support\CurrencyFormatter::format($item->subtotal_amount, $paymentSlip->currency ?? 'IDR') }}</td>
                                            <td class="checker-number">{{ \App\Support\CurrencyFormatter::format($item->tax_addition_amount, $paymentSlip->currency ?? 'IDR') }}</td>
                                            <td class="checker-number">{{ \App\Support\CurrencyFormatter::format($item->tax_deduction_amount, $paymentSlip->currency ?? 'IDR') }}</td>
                                            <td class="checker-number">{{ \App\Support\CurrencyFormatter::format($item->net_amount, $paymentSlip->currency ?? 'IDR') }}</td>
                                            @if ($showItemVat)
                                                <td>
                                                    <label class="checker-sr-only" for="item-{{ $item->id }}-vat">VAT invoice number for {{ $item->item_name }}</label>
                                                    <input
                                                        id="item-{{ $item->id }}-vat"
                                                        name="items[{{ $item->id }}][vat_invoice_number]"
                                                        value="{{ old("items.{$item->id}.vat_invoice_number", $item->vat_invoice_number) }}"
                                                        maxlength="255"
                                                        autocomplete="off"
                                                    >
                                                </td>
                                            @endif
                                            <td>
                                                <label class="checker-sr-only" for="item-{{ $item->id }}-coa">COA for {{ $item->item_name }}</label>
                                                <select id="item-{{ $item->id }}-coa" name="items[{{ $item->id }}][coa_id]">
                                                    <option value="">Select COA</option>
                                                    @foreach ($chartOfAccounts as $coa)
                                                        <option value="{{ $coa->id }}" @selected((string) old("items.{$item->id}.coa_id", $item->coa_id) === (string) $coa->id)>
                                                            {{ $coa->code }} — {{ $coa->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endforeach
            </div>

            <footer class="checker-footer">
                <p>Only VAT invoice numbers and item COA will be updated.</p>
                <button type="submit" class="checker-button checker-button--primary">Save checker fields</button>
            </footer>
        </form>
    </main>
</body>
</html>
