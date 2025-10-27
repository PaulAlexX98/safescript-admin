<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class AppointmentsCalendar extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Appointments';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug            = 'appointments';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected string $view = 'filament.pages.appointments-calendar';

    public function getTitle(): string
    {
        return 'Appointments';
    }
}