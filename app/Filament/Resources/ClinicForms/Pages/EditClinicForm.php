<?php

namespace App\Filament\Resources\ClinicForms\Pages;

use App\Filament\Resources\ClinicForms\ClinicFormResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClinicForm extends EditRecord
{
    protected static string $resource = ClinicFormResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
