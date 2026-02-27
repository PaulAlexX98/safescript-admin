<?php

namespace App\Filament\Resources\UnpaidOrders\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;

class UnpaidOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('booking_heading')->content('Booking Details'),

            TextInput::make('created_at')
                ->label('Booking Date'),

            TextInput::make('meta.service')
                ->label('Appointment Name'),

            Placeholder::make('patient_heading')->content('Patient'),

            TextInput::make('meta.firstName')
                ->label('First Name'),

            TextInput::make('meta.lastName')
                ->label('Last Name'),

            TextInput::make('meta.dob')
                ->label('DOB'),
        ]);
    }
}
