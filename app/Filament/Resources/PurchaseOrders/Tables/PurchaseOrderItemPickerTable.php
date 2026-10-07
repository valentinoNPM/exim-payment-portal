<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Models\Item;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderItemPickerTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('is_active', true)
                ->with('unit'))
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
                TextColumn::make('unit.code')
                    ->label('Satuan')
                    ->placeholder('—')
                    ->formatStateUsing(function (?string $state, Item $record): string {
                        $unitName = $record->unit?->name;

                        if (blank($state)) {
                            return '—';
                        }

                        if (blank($unitName) || strcasecmp($state, $unitName) === 0) {
                            return $state;
                        }

                        return "{$state} — {$unitName}";
                    })
                    ->sortable(),
            ])
            ->defaultSort('name');
    }
}
