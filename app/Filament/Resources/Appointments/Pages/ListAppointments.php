<?php

namespace App\Filament\Resources\Appointments\AppointmentResource\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return []; // add CreateAction if you want to create from admin
    }
}