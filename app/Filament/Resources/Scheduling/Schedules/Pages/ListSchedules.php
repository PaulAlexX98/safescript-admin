<?php

namespace App\Filament\Resources\Scheduling\Schedules\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Scheduling\Schedules\ScheduleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListSchedules extends ListRecords
{
    protected static string $resource = ScheduleResource::class;
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}