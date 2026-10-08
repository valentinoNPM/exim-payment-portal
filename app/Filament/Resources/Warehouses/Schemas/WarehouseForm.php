<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('source_code')
                    ->label('Kode Gudang')
                    ->placeholder('Contoh: GA')
                    ->required()
                    ->disabledOn('edit')
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Nama Gudang')
                    ->placeholder('Contoh: GENERAL')
                    ->required()
                    ->maxLength(255),
                TextInput::make('type')
                    ->label('Tipe')
                    ->placeholder('Contoh: Gudang')
                    ->maxLength(50),
                TextInput::make('branch')
                    ->label('Cabang / Perusahaan')
                    ->placeholder('Contoh: PT HANSOLL INDO JAVA')
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
