<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Services\PurchaseOrders\FinalizePurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Lihat PO';

    protected string $view = 'filament.purchase-orders.view';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('finalize')->label('Finalisasi PO')->color('primary')->requiresConfirmation()
                ->modalDescription('Konfirmasi bahwa dokumen fisik telah disetujui manager dan direktur. Data PO dikunci sampai Final dibatalkan, atau permanen setelah slip diterbitkan.')
                ->visible(fn () => $this->record->isPaymentSlipEligibleSource() && ! $this->record->finalized_at && ! $this->record->hasIssuedPaymentSlip())
                ->action(fn () => $this->record = app(FinalizePurchaseOrder::class)->execute($this->record, auth()->user())),
            Action::make('reopen')->label('Batalkan Final')->color('gray')->requiresConfirmation()
                ->visible(fn () => $this->record->finalized_at && ! $this->record->hasIssuedPaymentSlip())
                ->action(fn () => $this->record = app(FinalizePurchaseOrder::class)->execute($this->record, auth()->user(), true)),
            Action::make('issue')->label('Terbitkan Payment Slip')->color('primary')
                ->visible(fn () => $this->record->isPaymentSlipEligibleSource() && $this->record->finalized_at && ! $this->record->hasIssuedPaymentSlip())
                ->url(fn () => PurchaseOrderResource::getUrl('issue-payment-slip', ['record' => $this->record])),
            Action::make('slip')->label('Lihat Payment Slip')
                ->visible(fn () => $this->record->invoices()->exists())
                ->url(fn () => PaymentSlipResource::getUrl('view', ['record' => $this->record->invoices()->value('payment_slip_id')])),
            Action::make('preview_pdf')
                ->label('Preview PDF')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('info')
                ->url(fn (): string => route('purchase-orders.pdf.preview', $this->getRecord()))
                ->openUrlInNewTab(),
            EditAction::make()->visible(fn (): bool => PurchaseOrderResource::canEdit($this->getRecord())),
        ];
    }
}
