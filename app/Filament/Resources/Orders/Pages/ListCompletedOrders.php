<?php

namespace App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Orders\CompletedOrderResource;

class ListCompletedOrders extends ListRecords
{
    protected static string $resource = CompletedOrderResource::class;
}