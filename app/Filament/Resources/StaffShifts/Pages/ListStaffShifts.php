<?php

namespace App\Filament\Resources\StaffShifts\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Actions\Action;
use App\Filament\Resources\StaffShifts\StaffShiftResource;

class ListStaffShifts extends ListRecords
{
    protected static string $resource = StaffShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rota')
                ->label('Rota schedule')
                ->icon('heroicon-o-calendar-days')
                ->url('/admin/rota-schedule')
                ->openUrlInNewTab(),
        ];
    }
}