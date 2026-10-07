<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Purchase Order';

    protected static ?string $navigationLabel = 'Daftar PO';

    protected static ?string $modelLabel = 'PO';

    protected static ?int $navigationSort = 2;

    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();

        if (static::canCreate()) {
            array_unshift($items, NavigationItem::make('New Purchase Order')
                ->url(fn (): string => static::getUrl('create'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->group(static::getNavigationGroup())
                ->sort(1));
        }

        return $items;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forGa();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->hasRole('maker') && $user->isGaDivision();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canView(Model $record): bool
    {
        return static::canAccess() && strtoupper((string) $record->division?->code) === 'GA';
    }

    public static function canEdit(Model $record): bool
    {
        return static::canView($record);
    }

    public static function canDelete(Model $record): bool
    {
        // Hanya PO yang belum dikirim ke vendor yang boleh dihapus.
        return static::canView($record) && $record->status === PurchaseOrder::STATUS_NEW;
    }

    public static function canDeleteAny(): bool
    {
        return static::canAccess();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
