<?php

namespace App\Filament\Resources\PatientEmailTemplates\Pages;

use App\Filament\Resources\PatientEmailTemplates\PatientEmailTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPatientEmailTemplate extends EditRecord
{
    protected static string $resource = PatientEmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
