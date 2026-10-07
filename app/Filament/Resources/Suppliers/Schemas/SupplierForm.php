<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('source_code')
                    ->label('Kode ECOUNT')
                    ->helperText('Kode vendor di ECOUNT (acuan impor, bukan pengganti kode di atas)'),
                TextInput::make('name')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('email')
                    ->label('Email')
                    ->email(),
                TextInput::make('phone')
                    ->label('Telepon / HP')
                    ->tel(),
                TextInput::make('phone_digit')
                    ->label('Telepon (angka saja)')
                    ->helperText('Dipakai untuk tautan WhatsApp'),
                TextInput::make('fax')
                    ->label('Fax')
                    ->tel(),
                Textarea::make('address')
                    ->label('Alamat')
                    ->columnSpanFull(),
                Textarea::make('address_2')
                    ->label('Alamat 2 (alamat kirim)')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
