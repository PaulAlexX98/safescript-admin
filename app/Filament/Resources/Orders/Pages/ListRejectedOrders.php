<?php

namespace App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\Orders\RejectedOrderResource;

class ListRejectedOrders extends ListRecords
{
    protected static string $resource = RejectedOrderResource::class;
}