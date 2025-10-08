<?php

namespace App\Filament\Resources\PendingOrders\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\TextEntry;
use Filament\Schemas\Components\DateTimeEntry;

class PendingOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimeEntry::make('created_at')->label('Booking Date'),
                TextEntry::make('meta->service')->label('Appointment Name'),
                TextEntry::make('meta->firstName')->label('First Name'),
                TextEntry::make('meta->lastName')->label('Last Name'),
                TextEntry::make('meta->dob')->label('DOB'),
            ]);
    }
}
