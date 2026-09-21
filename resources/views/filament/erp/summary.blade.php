<x-filament::section heading="Payment Slip summary">
    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px">
        @foreach ([
            'Supplier' => $slip->supplier?->name.' ('.$slip->supplier?->code.')',
            'Type · Cost Center' => (\App\Models\PaymentSlip::TRANSACTION_TYPE_LABELS[$slip->transaction_type] ?? ucfirst($slip->transaction_type)).' · '.config('erp-export.cost_centers.'.$slip->transaction_type),
            'Invoice count' => $slip->invoices->count(),
            'Total expense' => 'Rp '.number_format($slip->invoices->sum(fn ($invoice) => $invoice->items->sum(fn ($item) => \App\Services\InvoiceAmountCalculator::roundRupiah((float) $item->subtotal_amount))), 2, ',', '.'),
            'PPN' => 'Rp '.number_format($slip->invoices->sum(fn ($invoice) => \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->tax_addition_amount)), 2, ',', '.'),
            'PPh' => 'Rp '.number_format($slip->invoices->sum(fn ($invoice) => \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->tax_deduction_amount)), 2, ',', '.'),
            'Net payable' => 'Rp '.number_format($slip->invoices->sum(fn ($invoice) => \App\Services\InvoiceAmountCalculator::roundRupiah((float) $invoice->grand_total_amount)), 2, ',', '.'),
        ] as $label => $value)
            <div><dt style="font-size:0.875rem;opacity:.7;margin-bottom:4px">{{ $label }}</dt><dd style="font-weight:500;font-variant-numeric:tabular-nums">{{ $value }}</dd></div>
        @endforeach
    </dl>
</x-filament::section>
