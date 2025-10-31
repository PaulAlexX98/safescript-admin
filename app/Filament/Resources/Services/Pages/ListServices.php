<?php

namespace App\Filament\Resources\Services\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListServices extends ListRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Service')
                ->createAnother(false),
        ];
    }
}
