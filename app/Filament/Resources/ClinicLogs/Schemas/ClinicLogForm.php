<?php

namespace App\Filament\Resources\ClinicLogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Forms;

class ClinicLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->required()
                ->options([
                    'info' => 'Info',
                    'warning' => 'Warning',
                    'error' => 'Error',
                ])
                ->native(false),
            TextInput::make('subject')
                ->maxLength(190)
                ->placeholder('Short summary'),
            Textarea::make('message')
                ->label('Message / Details')
                ->rows(5)
                ->required(),
            TextInput::make('user_id')
                ->label('User ID')
                ->numeric()
                ->helperText('Optional: who created this log.'),
            TextInput::make('patient_id')
                ->label('Patient ID')
                ->numeric()
                ->helperText('Optional: link to a patient.'),
            Textarea::make('context_json')
                ->label('Context (JSON)')
                ->rows(6)
                ->json()
                ->dehydrated(fn ($state) => filled($state))
                ->columnSpanFull(),
        ])->columns(2);
    }
}