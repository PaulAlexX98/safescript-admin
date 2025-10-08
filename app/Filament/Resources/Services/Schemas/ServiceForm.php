<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(190),
            Forms\Components\TextInput::make('code')
                ->maxLength(50)
                ->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('price')
                ->numeric()
                ->prefix('£')
                ->minValue(0)
                ->step(0.01)
                ->required(),
            Forms\Components\Select::make('category')
                ->options([
                    'vaccination' => 'Vaccination',
                    'consultation' => 'Consultation',
                    'screening' => 'Screening',
                    'other' => 'Other',
                ])
                ->native(false),
            Forms\Components\Toggle::make('active')
                ->default(true),
            Forms\Components\Textarea::make('description')
                ->rows(3)
                ->maxLength(3000)
                ->columnSpanFull(),
        ])->columns(2);
    }
}