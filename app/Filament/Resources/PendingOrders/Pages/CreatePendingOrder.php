<?php

namespace App\Filament\Resources\PendingOrders\Pages;

use App\Filament\Resources\PendingOrders\PendingOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePendingOrder extends CreateRecord
{
    protected static string $resource = PendingOrderResource::class;
}
