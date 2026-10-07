@if ($invoices->isEmpty())
    <p class="po-view__empty">Belum ada invoice yang tertaut ke PO ini.</p>
@else
    <div class="po-view__scroll">
        <table class="po-view__table">
            <thead>
                <tr>
                    <th>Nomor Invoice</th>
                    <th>Tanggal</th>
                    <th class="po-view__num">Nilai</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td class="po-view__code">{{ $invoice->invoice_number }}</td>
                        <td>{{ $invoice->invoice_date?->translatedFormat('d M Y') ?? '—' }}</td>
                        <td class="po-view__num">
                            {{ \App\Support\CurrencyFormatter::format($invoice->grand_total_amount, $invoice->paymentSlip?->currency ?? 'IDR') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
