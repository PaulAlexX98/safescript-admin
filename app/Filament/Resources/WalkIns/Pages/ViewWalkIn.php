<?php

namespace App\Filament\Resources\WalkIns\Pages;

use App\Filament\Resources\WalkIns\WalkInResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWalkIn extends ViewRecord
{
    protected static string $resource = WalkInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
