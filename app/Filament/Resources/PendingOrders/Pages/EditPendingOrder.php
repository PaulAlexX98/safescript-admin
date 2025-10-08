<?php

namespace App\Filament\Resources\PendingOrders\Pages;

use App\Filament\Resources\PendingOrders\PendingOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPendingOrder extends EditRecord
{
    protected static string $resource = PendingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
