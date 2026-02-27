<?php

namespace App\Filament\Resources\UnpaidOrders\Pages;

use App\Filament\Resources\UnpaidOrders\UnpaidOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUnpaidOrder extends CreateRecord
{
    protected static string $resource = UnpaidOrderResource::class;
}
