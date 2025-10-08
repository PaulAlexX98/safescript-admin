<?php

namespace App\Filament\Resources\ApprovedOrders\Schemas;

use Filament\Schemas\Schema;

class ApprovedOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                DateTimeEntry::make('created_at')->label('Order Date'),
                TextEntry::make('meta->service')->label('Appointment Name'),
                TextEntry::make('meta->firstName')->label('First Name'),
                TextEntry::make('meta->lastName')->label('Last Name'),
                TextEntry::make('meta->dob')->label('DOB'),
            ]);
    }
}
