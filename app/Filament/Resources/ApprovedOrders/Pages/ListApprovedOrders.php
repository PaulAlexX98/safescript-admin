<?php

namespace App\Filament\Resources\ApprovedOrders\Pages;

use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListApprovedOrders extends ListRecords
{
    protected static string $resource = ApprovedOrderResource::class;

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
