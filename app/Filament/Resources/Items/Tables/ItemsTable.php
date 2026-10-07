<?php

namespace App\Filament\Resources\Items\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode Sistem')->searchable()->sortable(),
                TextColumn::make('source_code')->label('Kode ECOUNT')->searchable(),
                TextColumn::make('name')->label('Nama Item')->searchable()->sortable(),
                TextColumn::make('specification')->label('Spesifikasi')->limit(50)->toggleable(),
                TextColumn::make('unit.code')->label('Satuan')->sortable(),
                TextColumn::make('source')->label('Sumber')->badge(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
