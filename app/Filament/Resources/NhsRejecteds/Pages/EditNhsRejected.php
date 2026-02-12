<?php

namespace App\Filament\Resources\NhsRejecteds\Pages;

use App\Filament\Resources\NhsRejecteds\NhsRejectedResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNhsRejected extends EditRecord
{
    protected static string $resource = NhsRejectedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
