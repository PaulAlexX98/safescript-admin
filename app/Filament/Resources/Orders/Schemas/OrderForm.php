<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Schemas\Schema;
use Filament\Infolists\Components\TextEntry;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('created_at')->label('Booking Date')->dateTime('d-m-Y H:i'),
                TextEntry::make('meta->service')->label('Appointment Name'),
                TextEntry::make('meta->firstName')->label('First Name'),
                TextEntry::make('meta->lastName')->label('Last Name'),
                TextEntry::make('meta->dob')->label('DOB'),
            ]);
    }
}