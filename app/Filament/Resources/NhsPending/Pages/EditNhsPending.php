<?php

namespace App\Filament\Resources\NhsPending\Pages;

use App\Filament\Resources\NhsPending\NhsPendingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNhsPending extends EditRecord
{
    protected static string $resource = NhsPendingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
