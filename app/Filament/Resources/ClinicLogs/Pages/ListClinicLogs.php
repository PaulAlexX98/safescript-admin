<?php

namespace App\Filament\Resources\ClinicLogs\Pages;

use App\Filament\Resources\ClinicLogs\ClinicLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicLogs extends ListRecords
{
    protected static string $resource = ClinicLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
