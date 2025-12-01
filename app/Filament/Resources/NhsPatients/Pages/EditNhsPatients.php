<?php

namespace App\Filament\Resources\NhsPatients\Pages;

use App\Filament\Resources\NhsPatients\NhsPatientsResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNhsPatients extends EditRecord
{
    protected static string $resource = NhsPatientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
