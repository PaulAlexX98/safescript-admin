<?php

namespace App\Filament\Resources\StaffShifts\Pages;

use App\Filament\Resources\StaffShifts\StaffShiftResource;
use App\Models\StaffShift;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListStaffShifts extends ListRecords
{
    protected static string $resource = StaffShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearAll')
                ->label('Clear all')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Clear all responsible pharmacist logs?')
                ->modalDescription(fn (): string => 'This will permanently delete all '
                    . number_format(StaffShift::query()->count())
                    . ' log records. This action cannot be undone.')
                ->modalSubmitActionLabel('Clear all logs')
                ->disabled(fn (): bool => ! StaffShift::query()->exists())
                ->action(function (): void {
                    $deleted = DB::transaction(
                        fn (): int => StaffShift::query()->delete()
                    );

                    Notification::make()
                        ->success()
                        ->title('Responsible pharmacist logs cleared')
                        ->body(number_format($deleted) . ' log records were deleted.')
                        ->send();
                }),
        ];
    }
}
