<?php

namespace App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Orders\UnpaidOrderResource;

class ListUnpaidOrders extends ListRecords
{
    protected static string $resource = UnpaidOrderResource::class;
}