<?php

namespace App\Filament\Resources\NhsApprovals\Pages;

use App\Filament\Resources\NhsApprovals\NhsApprovalResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNhsApproval extends ViewRecord
{
    protected static string $resource = NhsApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
