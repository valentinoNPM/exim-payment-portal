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
            $transactionDescription = \App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$slip->transaction_type] ?? $slip->transaction_type;
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
                        @if(filled($slip->invoice_receipt_number))
                        <tr>
                            <td class="data-label">Tanda Terima Invoice</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->invoice_receipt_number }}</td>
                        </tr>
                        @endif
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
                            <td class="data-label" style="width: 25%;">No. Invoice</td>
                            <td class="data-separator" style="width: 5%;">:</td>
                            <td class="data-value" style="width: 70%;">{{ $invoiceNumbers }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Detail Table -->
        <table class="detail-table">
            <thead>
                <tr>
                    <th style="width: 37%;">Detail</th>
                    <th style="width: 15%;">Amount</th>
                    <th style="width: 15%;">PPN</th>
                    <th style="width: 15%;">PPH</th>
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
                    $invSubtotal = \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->subtotal_amount);
                    $invPpn = \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->tax_addition_amount);
                    $invPph = \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->tax_deduction_amount);
                    $invGrand = $invSubtotal + $invPpn - $invPph;
                    $totalSubtotal += $invSubtotal;
                    $totalPpn += $invPpn;
                    $totalPph += $invPph;
                    $totalGrand += $invGrand;
                @endphp

                @php
                    $detailRowCount++;

                    // Export and import slips show an invoice summary, not item details.
                    $description = "REF {$invoice->invoice_number}";
                    $invoiceBuyer = $invoice->buyer?->name ?: $slip->buyer?->name;
                    if ($invoiceBuyer) {
                        $description .= " - {$invoiceBuyer}";
                    }
                @endphp
                <tr>
                    <td>{{ $detailRowCount }}. {{ $description }}</td>
                    <td class="amount-cell">Rp {{ number_format($invSubtotal, 0, ',', '.') }}</td>
                    <td class="amount-cell">{{ $invPpn > 0 ? 'Rp ' . number_format($invPpn, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">{{ $invPph > 0 ? 'Rp ' . number_format($invPph, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">Rp {{ number_format($invGrand, 0, ',', '.') }}</td>
                </tr>
                @endforeach

                <!-- Empty spacer rows if few details -->
                @for($i = $detailRowCount; $i < 3; $i++)
                <tr>
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
                    <td class="summary-label" colspan="4">SUB TOTAL</td>
                    <td class="summary-value">Rp {{ number_format($totalSubtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPN
                        @php
                            $ppnLabel = $slip->invoices->first()?->ppnTax?->name;
                        @endphp
                        @if($slip->transaction_type !== 'import')
                            {{ $ppnLabel ? "({$ppnLabel})" : '(0%)' }}
                        @endif
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPpn, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPH
                        @php
                            $pphLabel = $slip->invoices->first()?->pphTax?->name;
                        @endphp
                        @if($slip->transaction_type !== 'import')
                            {{ $pphLabel ? "({$pphLabel})" : '(0%)' }}
                        @endif
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPph, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label grand-total" colspan="4">GRAND TOTAL</td>
                    <td class="summary-value grand-total">Rp {{ number_format($totalGrand, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>

@endsection
