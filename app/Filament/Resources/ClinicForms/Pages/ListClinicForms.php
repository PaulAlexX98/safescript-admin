<?php

namespace App\Filament\Resources\ClinicForms\Pages;

use App\Filament\Resources\ClinicForms\ClinicFormResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicForms extends ListRecords
{
    protected static string $resource = ClinicFormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
