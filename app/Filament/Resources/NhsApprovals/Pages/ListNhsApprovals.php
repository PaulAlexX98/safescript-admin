<?php

namespace App\Filament\Resources\NhsApprovals\Pages;

use App\Filament\Resources\NhsApprovals\NhsApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListNhsApprovals extends ListRecords
{
    protected static string $resource = NhsApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
