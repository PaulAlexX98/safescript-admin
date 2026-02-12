<?php

namespace App\Filament\Resources\NhsPending\Pages;

use App\Filament\Resources\NhsPending\NhsPendingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListNhsPending extends ListRecords
{
    protected static string $resource = NhsPendingResource::class;

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
