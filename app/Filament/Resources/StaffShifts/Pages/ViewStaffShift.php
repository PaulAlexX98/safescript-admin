<?php

namespace App\Filament\Resources\StaffShifts\Pages;

use App\Filament\Resources\StaffShifts\StaffShiftResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStaffShift extends ViewRecord
{
    protected static string $resource = StaffShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}