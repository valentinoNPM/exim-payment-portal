<?php

namespace App\Filament\Resources\PaymentSlips;

use App\Filament\Resources\PaymentSlips\Pages\CreateExportPaymentSlipEditor;
use App\Filament\Resources\PaymentSlips\Pages\CreateGeneralPaymentSlipEditor;
use App\Filament\Resources\PaymentSlips\Pages\CreateImportPaymentSlipEditor;
use App\Filament\Resources\PaymentSlips\Pages\CreatePaymentSlipEditor;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlipEditor;
use App\Filament\Resources\PaymentSlips\Pages\ListPaymentSlips;
use App\Filament\Resources\PaymentSlips\Pages\ViewPaymentSlip;
use App\Filament\Resources\PaymentSlips\Schemas\EximPaymentSlipForm;
use App\Filament\Resources\PaymentSlips\Schemas\GeneralPaymentSlipForm;
use App\Filament\Resources\PaymentSlips\Schemas\PaymentSlipInfolist;
use App\Filament\Resources\PaymentSlips\Tables\PaymentSlipsTable;
use App\Models\PaymentSlip;
use App\Models\User;
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

    protected static ?string $navigationLabel = 'Payment Slips';

    protected static ?int $navigationSort = 1;

    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();

        $user = auth()->user();
        if ($user?->hasRole('maker')) {
            $createItems = $user->isEximDivision()
                ? [
                    NavigationItem::make('New General Payment Slip')
                        ->url(fn (): string => static::getUrl('create-general'))
                        ->icon('heroicon-o-plus-circle')
                        ->group(static::getNavigationGroup())
                        ->sort(2),
                    NavigationItem::make('New EXIM Payment Slip')
                        ->url(fn (): string => static::getUrl('create'))
                        ->icon('heroicon-o-arrow-up-tray')
                        ->group(static::getNavigationGroup())
                        ->sort(3),
                ]
                : [
                    NavigationItem::make('New General Payment Slip')
                        ->url(fn (): string => static::getUrl('create-general'))
                        ->icon('heroicon-o-plus-circle')
                        ->group(static::getNavigationGroup())
                        ->sort(2),
                ];

            array_unshift($items, ...$createItems);
        }

        return $items;
    }

    public static function form(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        $type = $record instanceof PaymentSlip
            ? $record->transaction_type
            : $schema->getLivewire()->getFixedTransactionType();

        return $type === PaymentSlip::TYPE_GENERAL
            ? GeneralPaymentSlipForm::configure($schema)
            : EximPaymentSlipForm::configure($schema);
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
        if ($record->hasPurchaseOrderSource()) {
            return false;
        }
        $user = auth()->user();

        return $user?->hasRole('maker')
            && (int) $record->getAttribute('created_by') === (int) $user->getKey()
            && PaymentSlip::query()->whereKey($record->getKey())->value('status') === 'draft';
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('maker') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('maker') ?? false;
    }

    public static function canCreateType(?User $user, string $transactionType): bool
    {
        if (! $user?->hasRole('maker')) {
            return false;
        }

        if (in_array($transactionType, [PaymentSlip::TYPE_IMPORT, PaymentSlip::TYPE_EXPORT], true)) {
            return $user->isEximDivision();
        }

        return $transactionType === PaymentSlip::TYPE_GENERAL;
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
            'create' => CreatePaymentSlipEditor::route('/create'),
            'create-export' => CreateExportPaymentSlipEditor::route('/create/export'),
            'create-import' => CreateImportPaymentSlipEditor::route('/create/import'),
            'create-general' => CreateGeneralPaymentSlipEditor::route('/create/general'),
            'view' => ViewPaymentSlip::route('/{record}'),
            'edit' => EditPaymentSlipEditor::route('/{record}/edit'),
            'edit-accounting' => EditPaymentSlip::route('/{record}/edit-accounting'),
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
