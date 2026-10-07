<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderItemPickerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_active', true))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode Barang')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Barang')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
            ])
            ->defaultSort('name');
    }
}
