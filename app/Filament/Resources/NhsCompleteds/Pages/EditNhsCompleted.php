<?php

namespace App\Filament\Resources\NhsCompleteds\Pages;

use App\Filament\Resources\NhsCompleteds\NhsCompletedResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNhsCompleted extends EditRecord
{
    protected static string $resource = NhsCompletedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
