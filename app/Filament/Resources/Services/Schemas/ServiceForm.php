<?php

namespace App\Filament\Resources\Services\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Forms;

class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(190),
            TextInput::make('code')
                ->maxLength(50)
                ->unique(ignoreRecord: true),
            TextInput::make('price')
                ->numeric()
                ->prefix('£')
                ->minValue(0)
                ->step(0.01)
                ->required(),
            Select::make('category')
                ->options([
                    'vaccination' => 'Vaccination',
                    'consultation' => 'Consultation',
                    'screening' => 'Screening',
                    'other' => 'Other',
                ])
                ->native(false),
            Toggle::make('active')
                ->default(true),
            Textarea::make('description')
                ->rows(3)
                ->maxLength(3000)
                ->columnSpanFull(),
        ])->columns(2);
    }
}