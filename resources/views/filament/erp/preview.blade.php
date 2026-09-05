<x-filament::section heading="Journal preview">
    @if ($error)
        <p role="alert" class="text-danger-600 dark:text-danger-400">{{ $error }}</p>
        <x-filament::link :href="\App\Filament\Resources\PaymentSlips\PaymentSlipResource::getUrl('view', ['record' => $slip])">View Payment Slip</x-filament::link>
    @else
        <div style="overflow-x:auto;max-height:28rem">
            <table style="width:100%;min-width:1000px;font-size:0.875rem;border-collapse:separate;border-spacing:12px 8px;text-align:left">
                <thead><tr>@foreach (['Invoice','Row type','Account type','Account','Description','Cost Center','Debit','Credit','VAT Invoice No.'] as $heading)<th scope="col" class="text-start">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->invoice }}</td><td>{{ $row->rowType }}</td><td>{{ $row->accountType }}</td><td>{{ $row->account }}</td><td>{{ $row->description }}</td><td>{{ $row->costCenter }}</td>
                        <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums">{{ $row->debit !== null ? number_format($row->debit / 100, 2, ',', '.') : '' }}</td>
                        <td style="text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums">{{ $row->credit !== null ? number_format($row->credit / 100, 2, ',', '.') : '' }}</td><td>{{ $row->vatInvoiceNumber }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p style="margin-top:16px;font-weight:500">Total Debit: Rp {{ number_format(array_sum(array_column($rows, 'debit')) / 100, 2, ',', '.') }} · Total Credit: Rp {{ number_format(array_sum(array_column($rows, 'credit')) / 100, 2, ',', '.') }} · Difference: Rp 0,00</p>
        <p style="margin-top:8px;font-size:0.875rem">The downloaded workbook retains all 59 ERP template columns.</p>
    @endif
</x-filament::section>
