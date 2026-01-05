<?php

namespace App\Filament\Resources\Patients\Schemas;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Patient')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('internal_id')
                        ->label('Internal ID')
                        ->disabled()
                        ->dehydrated(false),
                ]),

            // Most editable fields are stored on the related user record.
            // This ensures changes show up immediately everywhere the admin reads from user columns.
            Section::make('Contact and address')
                ->columnSpanFull()
                ->relationship('user')
                ->columns(2)
                ->schema([
                    TextInput::make('first_name')
                        ->label('First Name')
                        ->required()
                        ->maxLength(190),

                    TextInput::make('last_name')
                        ->label('Last Name')
                        ->required()
                        ->maxLength(190),

                    DatePicker::make('dob')
                        ->label('Date of birth'),

                    Select::make('gender')
                        ->label('Gender')
                        ->options([
                            'male' => 'Male',
                            'female' => 'Female',
                            'other' => 'Other',
                        ]),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(190),

                    TextInput::make('phone')
                        ->label('Phone')
                        ->maxLength(30),

                    TextInput::make('address1')
                        ->label('Address line 1')
                        ->maxLength(190),

                    TextInput::make('address2')
                        ->label('Address line 2')
                        ->maxLength(190),

                    TextInput::make('city')
                        ->label('City')
                        ->maxLength(120),

                    TextInput::make('postcode')
                        ->label('Postcode')
                        ->maxLength(20),

                    TextInput::make('country')
                        ->label('Country')
                        ->maxLength(120),

                    TextInput::make('shipping_address1')
                        ->label('Shipping address line 1')
                        ->maxLength(190),

                    TextInput::make('shipping_address2')
                        ->label('Shipping address line 2')
                        ->maxLength(190),

                    TextInput::make('shipping_city')
                        ->label('Shipping city')
                        ->maxLength(120),

                    TextInput::make('shipping_postcode')
                        ->label('Shipping postcode')
                        ->maxLength(20),

                    TextInput::make('shipping_country')
                        ->label('Shipping country')
                        ->maxLength(120),
                ]),
        ]);
    }
}