<?php

namespace App\Filament\Resources\Taxes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TaxForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required(),
                TextInput::make('rate')
                    ->numeric()
                    ->required()
                    ->suffix('%'),
                Select::make('calculation_type')
                    ->options([
                        'addition' => 'Addition (e.g., PPN)',
                        'deduction' => 'Deduction (e.g., PPh)',
                    ])
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
