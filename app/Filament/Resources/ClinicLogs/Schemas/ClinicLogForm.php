<?php

namespace App\Filament\Resources\ClinicLogs\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms;

class ClinicLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\Select::make('type')
                ->required()
                ->options([
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'error' => 'Error',
                ])
                ->native(false),
            Forms\Components\TextInput::make('subject')
                ->maxLength(190)
                ->placeholder('Short summary'),
            Forms\Components\Textarea::make('message')
                ->label('Message / Details')
                ->rows(5)
                ->required(),
            Forms\Components\TextInput::make('user_id')
                ->label('User ID')
                ->numeric()
                ->helperText('Optional: who created this log.'),
            Forms\Components\TextInput::make('patient_id')
                ->label('Patient ID')
                ->numeric()
                ->helperText('Optional: link to a patient.'),
            Forms\Components\Textarea::make('context_json')
                ->label('Context (JSON)')
                ->rows(6)
                ->json()
                ->dehydrated(fn ($state) => filled($state))
                ->columnSpanFull(),
        ])->columns(2);
    }
}