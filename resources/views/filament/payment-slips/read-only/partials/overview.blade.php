@php
    $currency = $slip->transaction_type === \App\Models\PaymentSlip::TYPE_GENERAL ? ($slip->currency ?: 'IDR') : 'IDR';
    $statusLabel = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'pending_approval' => 'Pending Approval (legacy)',
        'approved' => 'Verified',
        'exported' => 'Exported',
    ][$slip->status] ?? $slip->status;
    $statusTone = match ($slip->status) {
        'approved', 'exported' => 'success',
        'submitted', 'pending_approval' => 'info',
        'cancelled', 'canceled' => 'muted',
        default => 'warning',
    };
@endphp

<div id="overview">
    <x-filament::section heading="Overview">
        <dl class="payment-slip-detail__overview">
            @foreach ($slip->invoices as $invoice)
                <div class="payment-slip-detail__overview-invoice" data-invoice-id="{{ $invoice->getKey() }}">
                    <dt>Invoice {{ $loop->iteration }}</dt>
                    <dd class="payment-slip-detail__money">{{ \App\Support\CurrencyFormatter::format($invoice->grand_total_amount, $currency) }}</dd>
                </div>
            @endforeach

            <div class="payment-slip-detail__overview-total">
                <dt>Total Amount</dt>
                <dd class="payment-slip-detail__money">{{ \App\Support\CurrencyFormatter::format($slip->grand_total_amount, $currency) }}</dd>
            </div>

            <div class="payment-slip-detail__overview-status">
                <dt>Status</dt>
                <dd><span class="payment-slip-detail__status payment-slip-detail__status--{{ $statusTone }}">{{ $statusLabel }}</span></dd>
            </div>
        </dl>
    </x-filament::section>
</div>
