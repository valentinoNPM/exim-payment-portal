@extends('pdf.payment-slips.layout')

@section('slipContent')
        <!-- Data Section -->
        @php
            $invoiceNumbers = $slip->invoices->pluck('invoice_number')->filter()->implode(', ');
            $department = $slip->creator?->division?->name ?? strtoupper($slip->transaction_type);
            $buyerNames = $slip->invoices
                ->map(fn ($invoice) => $invoice->buyer?->name ?: $slip->buyer?->name)
                ->filter()
                ->unique()
                ->implode(', ');
            $transactionDescription = filled($slip->transaction_description)
                ? $slip->transaction_description
                : (\App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$slip->transaction_type] ?? $slip->transaction_type);
        @endphp
        @php
            $purchaseOrderNumbers = $slip->invoices
                ->map(fn ($invoice) => $invoice->purchaseOrder?->po_number)
                ->filter(fn ($number) => filled($number))
                ->unique()
                ->values()
                ->implode(', ');
        @endphp
        <table style="width: 100%; border: none; margin-bottom: 8px;">
            <tr>
                <td style="width: 62%; vertical-align: top; border: none; padding: 0;">
                    <table class="data-table" style="width: 100%;">
                        <tr>
                            <td class="data-label" style="width: 32%;">Payment Slip Number</td>
                            <td class="data-separator" style="width: 4%;">:</td>
                            <td class="data-value" style="width: 64%;">{{ $slip->slip_number }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Date</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->created_at->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Department</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $department }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Name</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->creator?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Supplier</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->supplier?->name ?? '-' }}</td>
                        </tr>
                        @if($slip->customer)
                        <tr>
                            <td class="data-label">Customer</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->customer->name }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="data-label">Buyer</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $buyerNames ?: '-' }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Account Name</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">
                                @php
                                    $coaNames = $slip->invoices->flatMap(fn($inv) => $inv->items->pluck('coa_name_snapshot'))->filter()->unique()->implode(', ');
                                @endphp
                                {{ $coaNames ?: '-' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="data-label">Transaction</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">CHARGE {{ strtoupper($transactionDescription) }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 38%; vertical-align: top; border: none; padding: 0;">
                    <table class="data-table" style="width: 100%;">
                        <tr>
                            <td class="data-label" style="width: 36%;">No. Invoice</td>
                            <td class="data-separator" style="width: 4%;">:</td>
                            <td class="data-value" style="width: 60%;">{{ $invoiceNumbers }}</td>
                        </tr>
                        @if(filled($purchaseOrderNumbers))
                            <tr>
                                <td class="data-label" style="width: 36%;">Referensi PO</td>
                                <td class="data-separator" style="width: 4%;">:</td>
                                <td class="data-value" style="width: 60%;">{{ $purchaseOrderNumbers }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <!-- Detail Table -->
        <table class="detail-table">
            <thead>
                <tr>
                    <th style="width: 27%;">Detail</th>
                        <th style="width: 7%;">Quantity</th>
                        <th style="width: 9%;">Satuan</th>
                    <th style="width: 13%;">Amount</th>
                    <th style="width: 13%;">PPN</th>
                    <th style="width: 13%;">PPH</th>
                    <th style="width: 18%;">Amount Dibayar</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalSubtotal = 0;
                    $totalPpn = 0;
                    $totalPph = 0;
                    $totalGrand = 0;
                    $detailRowCount = 0;
                    $currency = $slip->currency ?? 'IDR';
                @endphp
                @foreach($slip->invoices as $idx => $invoice)
                @php
                    $invSubtotal = (float) $invoice->subtotal_amount;
                    $invPpn = (float) $invoice->tax_addition_amount;
                    $invPph = (float) $invoice->tax_deduction_amount;
                    $invGrand = (float) $invoice->grand_total_amount;
                    $totalSubtotal += $invSubtotal;
                    $totalPpn += $invPpn;
                    $totalPph += $invPph;
                    $totalGrand += $invGrand;
                @endphp

                    @foreach($invoice->items as $item)
                    @php
                        $detailRowCount++;
                        $quantity = rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',');
                        $usesDecimals = \App\Support\CurrencyFormatter::usesDecimals($currency);
                        $unitPrice = $usesDecimals
                            ? (float) $item->unit_price_amount
                            : \App\Services\InvoiceAmountCalculator::roundRupiah((float) $item->unit_price_amount);
                        $itemSubtotal = $usesDecimals
                            ? (float) $item->subtotal_amount
                            : \App\Services\InvoiceAmountCalculator::roundRupiah((float) $item->subtotal_amount);
                        $itemPpn = $usesDecimals
                            ? (float) $item->tax_addition_amount
                            : \App\Services\InvoiceAmountCalculator::roundRupiah((float) $item->tax_addition_amount);
                        $itemPph = $usesDecimals
                            ? (float) $item->tax_deduction_amount
                            : \App\Services\InvoiceAmountCalculator::roundRupiah((float) $item->tax_deduction_amount);
                        $itemNet = $itemSubtotal + $itemPpn - $itemPph;
                    @endphp
                    <tr>
                        <td>
                            {{ $detailRowCount }}. {{ $item->item_name }}
                        </td>
                        <td style="text-align: center;">{{ $quantity }}</td>
                        <td style="text-align: center;">{{ $invoice->unitNameFor($item) ?? '-' }}</td>
                        <td class="amount-cell">{{ \App\Support\CurrencyFormatter::format($unitPrice, $currency) }}</td>
                        <td class="amount-cell">{{ $itemPpn > 0 ? \App\Support\CurrencyFormatter::format($itemPpn, $currency) : '-' }}</td>
                        <td class="amount-cell">{{ $itemPph > 0 ? \App\Support\CurrencyFormatter::format($itemPph, $currency) : '-' }}</td>
                        <td class="amount-cell">{{ \App\Support\CurrencyFormatter::format($itemNet, $currency) }}</td>
                    </tr>
                    @endforeach
                    @if((float) $invoice->discount_amount > 0)
                        <tr><td colspan="6">Diskon (-)</td><td class="amount-cell">{{ \App\Support\CurrencyFormatter::format($invoice->discount_amount, $currency) }}</td></tr>
                    @endif
                    @if((float) $invoice->shipping_amount > 0)
                        <tr><td colspan="6">Ongkos Kirim (+)</td><td class="amount-cell">{{ \App\Support\CurrencyFormatter::format($invoice->shipping_amount, $currency) }}</td></tr>
                    @endif
                @endforeach

                <!-- Empty spacer rows if few details -->
                @for($i = $detailRowCount; $i < 3; $i++)
                <tr>
                    <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                @endfor
            </tbody>

            <!-- Summary Rows -->
            <tfoot>
                <tr>
                    <td class="summary-label" colspan="6">SUB TOTAL</td>
                    <td class="summary-value">{{ \App\Support\CurrencyFormatter::format($totalSubtotal, $currency) }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="6">
                        PPN
                    </td>
                    <td class="summary-value">{{ \App\Support\CurrencyFormatter::format($totalPpn, $currency) }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="6">
                        PPH
                    </td>
                    <td class="summary-value">{{ \App\Support\CurrencyFormatter::format($totalPph, $currency) }}</td>
                </tr>
                <tr>
                    <td class="summary-label grand-total" colspan="6">GRAND TOTAL</td>
                    <td class="summary-value grand-total">{{ \App\Support\CurrencyFormatter::format($totalGrand, $currency) }}</td>
                </tr>
            </tfoot>
        </table>

@endsection
