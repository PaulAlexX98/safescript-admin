<?php

namespace App\Filament\Resources\ClinicLogs\Pages;

use App\Filament\Resources\ClinicLogs\ClinicLogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClinicLog extends ViewRecord
{
    protected static string $resource = ClinicLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
