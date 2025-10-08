<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
    
    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if (request()->query('filter') === 'upcoming') {
            if ($query instanceof Builder) {
                return $query->upcoming();
            }

            if ($query instanceof Relation) {
                return $query->getQuery()->upcoming();
            }

            return $query; // null
        }

        return $query;
    }
}
