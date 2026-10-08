<?php

namespace App\Filament\Resources\Warehouses\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_code')
                    ->label('Kode Gudang')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Gudang')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipe')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('branch')
                    ->label('Cabang / Perusahaan')
                    ->searchable()
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('purchase_orders_count')
                    ->label('Jumlah PO')
                    ->counts('purchaseOrders')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
