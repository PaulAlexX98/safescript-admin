<?php

namespace App\Filament\Resources\NhsCompleteds\Pages;

use App\Filament\Resources\NhsCompleteds\NhsCompletedResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNhsCompleteds extends ListRecords
{
    protected static string $resource = NhsCompletedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
