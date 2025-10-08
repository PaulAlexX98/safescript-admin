<?php

namespace App\Filament\Resources\ClinicLogs\Pages;

use App\Filament\Resources\ClinicLogs\ClinicLogResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClinicLog extends EditRecord
{
    protected static string $resource = ClinicLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
