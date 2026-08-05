<?php

namespace App\Filament\Resources\PatientEmailTemplates\Pages;

use App\Filament\Resources\PatientEmailTemplates\PatientEmailTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePatientEmailTemplate extends CreateRecord
{
    protected static string $resource = PatientEmailTemplateResource::class;
}
