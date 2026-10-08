<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Support\PurchaseOrderEditorData;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class EditPurchaseOrder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Ubah PO';

    protected string $view = 'filament.resources.purchase-orders.pages.editor';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(PurchaseOrderResource::canEdit($this->getRecord()), 403);
    }

    /** @return array<ViewAction|DeleteAction> */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (): bool => PurchaseOrderResource::canDelete($this->getRecord())),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        return app(PurchaseOrderEditorData::class)->for($record);
    }
}
