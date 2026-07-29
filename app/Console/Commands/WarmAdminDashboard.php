<?php

namespace App\Console\Commands;

use App\Filament\Widgets\BookingStatusTable;
use App\Filament\Widgets\DailyRevenueTable;
use App\Filament\Widgets\KpiStats;
use App\Filament\Widgets\RevenueBookingsChart;
use App\Filament\Widgets\ServicesPerformance;
use Illuminate\Console\Command;

class WarmAdminDashboard extends Command
{
    protected $signature = 'admin:warm-dashboard';

    protected $description = 'Warm the default admin dashboard report caches';

    public function handle(): int
    {
        foreach ([
            KpiStats::class,
            BookingStatusTable::class,
            RevenueBookingsChart::class,
            ServicesPerformance::class,
            DailyRevenueTable::class,
        ] as $widget) {
            (new $widget)->warmCache();
        }

        $this->info('Admin dashboard caches warmed.');

        return self::SUCCESS;
    }
}
