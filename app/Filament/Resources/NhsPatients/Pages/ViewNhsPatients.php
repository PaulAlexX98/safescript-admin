<?php

namespace App\Filament\Resources\NhsPatients\Pages;

use App\Filament\Resources\NhsPatients\NhsPatientsResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNhsPatients extends ViewRecord
{
    protected static string $resource = NhsPatientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
