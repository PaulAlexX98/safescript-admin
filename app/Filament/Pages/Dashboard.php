<?php
// app/Filament/Pages/Dashboard.php
namespace App\Filament\Pages;

use Filament\Pages\Dashboard as Base;
use App\Filament\Widgets\BookingStatusTable;
use App\Filament\Widgets\KpiStats;
use App\Filament\Widgets\RevenueBookingsChart;
use App\Filament\Widgets\ServicesPerformance;
use App\Filament\Widgets\DailyRevenueTable;

class Dashboard extends Base
{
    public function getWidgets(): array
    {
        return [
            KpiStats::class,               // KPI tiles at top
            ServicesPerformance::class,    // left column     
            BookingStatusTable::class,     // full width below the two-column row
            RevenueBookingsChart::class, 
            DailyRevenueTable::class,   // full width at the very bottom (ensures below calendar)
        ];
    }

    public function getColumns(): int|string|array
    {
        // two columns on large screens so full spans can stretch
        return [
            'sm' => 1,
            'lg' => 2,
            '2xl' => 2,
        ];
    }
}