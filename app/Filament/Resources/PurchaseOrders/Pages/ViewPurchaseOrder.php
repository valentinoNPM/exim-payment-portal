<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
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
            Action::make('preview_pdf')
                ->label('Preview PDF')
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('info')
                ->url(fn (): string => route('purchase-orders.pdf.preview', $this->getRecord()))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
