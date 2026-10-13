<?php

namespace App\Filament\Resources\PendingOrders\Pages;

use App\Filament\Resources\PendingOrders\PendingOrderResource;
use App\Filament\Resources\PendingOrders\Widgets\ManualAppointmentsPending;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListPendingOrders extends ListRecords
{
    protected static string $resource = PendingOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ManualAppointmentsPending::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('ncrs')
                ->label('NCRS portal')
                ->url('https://digital.nhs.uk/services/national-care-records-service')
                ->openUrlInNewTab()
                ->button(),
        ];
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();
        $status = request()->query('status');

        if ($status === 'nhs_pending') {
            if ($query instanceof Builder) {
                return $query->pendingNhs();
            }
            if ($query instanceof Relation) {
                return $query->getQuery()->pendingNhs();
            }

            return $query; // null
        }

        if ($status === 'awaiting_approval') {
            if ($query instanceof Builder) {
                return $query->pendingApproval();
            }
            if ($query instanceof Relation) {
                return $query->getQuery()->pendingApproval();
            }

            return $query; // null
        }

        return $query;
    }
}
