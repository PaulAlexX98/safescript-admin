<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\CompletedOrderResource;
use App\Support\AdminNavigationCounts;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ListCompletedOrders extends ListRecords
{
    protected static string $resource = CompletedOrderResource::class;

    /**
     * Keep the fast page query while restoring the "x–y of total" footer.
     * The total is refreshed out of band with the navigation counters.
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        if (filled($this->getTableSearch())) {
            return parent::paginateTableQuery($query);
        }

        $perPage = $this->getTableRecordsPerPage();

        if ($perPage === 'all' || ! is_numeric($perPage)) {
            return parent::paginateTableQuery($query);
        }

        $perPage = (int) $perPage;
        $pageName = $this->getTablePaginationPageName();
        $page = (int) $this->getTablePage();
        $records = $query->forPage($page, $perPage)->get();
        $minimumTotal = (($page - 1) * $perPage) + $records->count();
        $total = max(
            $minimumTotal,
            AdminNavigationCounts::all()['completed']
        );

        return (new LengthAwarePaginator(
            $records,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        ))->onEachSide(0);
    }
}
