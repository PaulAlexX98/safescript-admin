<?php

namespace App\Filament\Resources\WalkIns\Pages;

use App\Filament\Resources\WalkIns\WalkInResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWalkIn extends EditRecord
{
    protected static string $resource = WalkInResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
