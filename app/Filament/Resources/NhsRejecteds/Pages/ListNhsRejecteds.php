<?php

namespace App\Filament\Resources\NhsRejecteds\Pages;

use App\Filament\Resources\NhsRejecteds\NhsRejectedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNhsRejecteds extends ListRecords
{
    protected static string $resource = NhsRejectedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
