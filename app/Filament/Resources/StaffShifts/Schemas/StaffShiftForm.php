<?php

namespace App\Filament\Resources\StaffShifts\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class StaffShiftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('created_by')
                    ->numeric()
                    ->default(null),
                DatePicker::make('shift_date')
                    ->required(),
                DateTimePicker::make('clocked_in_at'),
                DateTimePicker::make('clocked_out_at'),
                TextInput::make('clock_in_ip')
                    ->default(null),
                TextInput::make('clock_out_ip')
                    ->default(null),
                Textarea::make('clock_in_ua')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('clock_out_ua')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
