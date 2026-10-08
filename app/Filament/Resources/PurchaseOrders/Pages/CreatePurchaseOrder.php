<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Support\PurchaseOrderEditorData;
use Filament\Resources\Pages\Page;
use UnitEnum;

class CreatePurchaseOrder extends Page
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'PO Baru';

    protected static ?string $navigationLabel = 'PO Baru';

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Order';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = true;

    protected string $view = 'filament.resources.purchase-orders.pages.editor';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return app(PurchaseOrderEditorData::class)->for();
    }
}
