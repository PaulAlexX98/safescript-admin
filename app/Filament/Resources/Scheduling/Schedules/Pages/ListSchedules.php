<?php

namespace App\Filament\Resources\Scheduling\Schedules\ScheduleResource\Pages;

use App\Filament\Resources\Scheduling\Schedules\ScheduleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}