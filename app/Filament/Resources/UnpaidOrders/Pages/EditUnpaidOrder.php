<?php

namespace App\Filament\Resources\UnpaidOrders\Pages;

use App\Filament\Resources\UnpaidOrders\UnpaidOrderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUnpaidOrder extends EditRecord
{
    protected static string $resource = UnpaidOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
