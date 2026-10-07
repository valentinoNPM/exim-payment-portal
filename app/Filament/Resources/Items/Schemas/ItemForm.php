<?php

namespace App\Filament\Resources\Items\Schemas;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('code')
                ->label('Kode Sistem')
                ->content(fn ($record): string => $record?->code ?? '-')
                ->hiddenOn('create'),
            TextInput::make('name')
                ->label('Nama Barang')
                ->required()
                ->maxLength(255),
            Textarea::make('specification')
                ->label('Spesifikasi')
                ->columnSpanFull(),
            Select::make('unit_id')
                ->label('Satuan')
                ->relationship('unit', 'name')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->code.' - '.$record->name)
                ->searchable(['code', 'name'])
                ->preload(),
            Hidden::make('source')->default('manual'),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->required(),
        ]);
    }
}
