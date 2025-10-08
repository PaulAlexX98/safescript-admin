<?php

namespace App\Filament\Resources\ApprovedOrders\Pages;

use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApprovedOrder extends CreateRecord
{
    protected static string $resource = ApprovedOrderResource::class;
}
