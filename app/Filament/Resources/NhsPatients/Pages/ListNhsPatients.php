<?php

namespace App\Filament\Resources\NhsPatients\Pages;

use App\Filament\Resources\NhsPatients\NhsPatientsResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNhsPatients extends ListRecords
{
    protected static string $resource = NhsPatientsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
