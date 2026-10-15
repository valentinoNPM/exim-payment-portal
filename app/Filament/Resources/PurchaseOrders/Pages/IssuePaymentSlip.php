<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\PurchaseOrders\FinalizePurchaseOrder;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class IssuePaymentSlip extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Terbitkan Payment Slip General';

    protected string $view = 'filament.purchase-orders.issue-payment-slip';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        FinalizePurchaseOrder::authorize($this->record, auth()->user());
        abort_unless($this->record->isPaymentSlipEligibleSource() && $this->record->finalized_at && ! $this->record->hasIssuedPaymentSlip(), 403);
    }
}
