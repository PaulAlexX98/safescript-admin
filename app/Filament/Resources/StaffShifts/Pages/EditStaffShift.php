<?php

namespace App\Filament\Resources\StaffShifts\Pages;

use App\Filament\Resources\StaffShifts\StaffShiftResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStaffShift extends EditRecord
{
    protected static string $resource = StaffShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
