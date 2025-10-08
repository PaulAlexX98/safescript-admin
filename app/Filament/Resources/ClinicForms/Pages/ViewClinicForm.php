<?php

namespace App\Filament\Resources\ClinicForms\Pages;

use App\Filament\Resources\ClinicForms\ClinicFormResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewClinicForm extends ViewRecord
{
    protected static string $resource = ClinicFormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
