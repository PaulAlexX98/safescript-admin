<?php

namespace App\Filament\Resources\NhsApprovals\Pages;

use App\Filament\Resources\NhsApprovals\NhsApprovalResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditNhsApproval extends EditRecord
{
    protected static string $resource = NhsApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
