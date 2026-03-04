<?php

namespace App\Filament\Resources\StaffShifts\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class StaffShiftInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('User'),
                TextEntry::make('created_by')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('shift_date')
                    ->date(),
                TextEntry::make('clocked_in_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('clocked_out_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('clock_in_ip')
                    ->placeholder('-'),
                TextEntry::make('clock_out_ip')
                    ->placeholder('-'),
                TextEntry::make('clock_in_ua')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('clock_out_ua')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
