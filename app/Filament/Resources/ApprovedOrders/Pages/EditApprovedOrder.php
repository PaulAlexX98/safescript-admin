<?php

namespace App\Filament\Resources\ApprovedOrders\Pages;

use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditApprovedOrder extends EditRecord
{
    protected static string $resource = ApprovedOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
