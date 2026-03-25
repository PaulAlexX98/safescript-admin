<?php

namespace App\Filament\Resources\WalkIns\Pages;

use App\Filament\Resources\WalkIns\WalkInResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWalkIns extends ListRecords
{
    protected static string $resource = WalkInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
