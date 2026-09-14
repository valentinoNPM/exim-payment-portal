<?php

namespace App\Filament\Resources\PaymentSlips;

use App\Filament\Resources\PaymentSlips\Pages\CreatePaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\ListPaymentSlips;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Filament\Resources\PaymentSlips\Schemas\PaymentSlipForm;
use App\Filament\Resources\PaymentSlips\Schemas\PaymentSlipInfolist;
use App\Filament\Resources\PaymentSlips\Tables\PaymentSlipsTable;
use App\Models\PaymentSlip;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentSlipResource extends Resource
{
    protected static ?string $model = PaymentSlip::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Payment';

    protected static ?int $navigationSort = 2;

    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();

        if (auth()->check() && auth()->user()->hasRole('maker')) {
            array_unshift(
                $items,
                NavigationItem::make('New Payment Slip')
                    ->url(fn (): string => static::getUrl('create'))
                    ->icon('heroicon-o-plus-circle')
                    ->group(static::getNavigationGroup())
                    ->sort(1)
            );
        }

        return $items;
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentSlipForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->hasRole('maker') && ! $user->hasAnyRole(['checker', 'approver'])) {
            $query->where('created_by', $user->getKey());
        }

        return $query;
    }

    public static function canView(Model $record): bool
    {
        return static::canAccessRecord($record);
    }

    public static function canEdit(Model $record): bool
    {
        $status = PaymentSlip::query()->whereKey($record->getKey())->value('status');
        $user = auth()->user();

        if (! $user || ! static::canAccessRecord($record)) {
            return false;
        }

        if ($user->hasRole('checker')) {
            return in_array($status, ['draft', 'submitted'], true);
        }

        return $status === 'draft' && $user->hasRole('maker');
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        return $user?->hasRole('maker')
            && (int) $record->getAttribute('created_by') === (int) $user->getKey()
            && PaymentSlip::query()->whereKey($record->getKey())->value('status') === 'draft';
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('maker') ?? false;
    }

    public static function canSubmit(Model $record): bool
    {
        $user = auth()->user();

        return $user?->hasRole('maker')
            && (int) $record->getAttribute('created_by') === (int) $user->getKey()
            && PaymentSlip::query()->whereKey($record->getKey())->value('status') === 'draft';
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentSlipInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentSlipsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentSlips::route('/'),
            'create' => CreatePaymentSlip::route('/create'),
            'view' => ViewPaymentSlip::route('/{record}'),
            'edit' => EditPaymentSlip::route('/{record}/edit'),
        ];
    }

    private static function canAccessRecord(Model $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['checker', 'approver'])) {
            return true;
        }

        return $user->hasRole('maker')
            && (int) $record->getAttribute('created_by') === (int) $user->getKey();
    }
}
