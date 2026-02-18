<?php
// app/Filament/Pages/Dashboard.php
namespace App\Filament\Pages;

use Filament\Pages\Dashboard as Base;
use App\Filament\Widgets\BookingStatusTable;
use App\Filament\Widgets\KpiStats;
use App\Filament\Widgets\RevenueBookingsChart;
use App\Filament\Widgets\ServicesPerformance;
use App\Filament\Widgets\DailyRevenueTable;
use App\Filament\Resources\Appointments\AppointmentResource as AppointmentsAppointmentResource;

class Dashboard extends Base
{
    public static function shouldRegisterNavigation(): bool
    {
        $u = auth()->user();

        // Hide Dashboard from the sidebar for this specific user.
        return (bool) $u
            && (int) ($u->is_staff ?? 0) === 1
            && strtolower((string) ($u->email ?? '')) !== 'info@safescript.co.uk';
    }

    public function mount(): void
    {
        $u = auth()->user();

        // Filament mounts Dashboard at /admin. Do not 403; redirect this user to Appointments.
        if (
            $u
            && (int) ($u->is_staff ?? 0) === 1
            && strtolower((string) ($u->email ?? '')) === 'info@safescript.co.uk'
        ) {
            $this->redirect('/admin/pending-orders');
            return;
        }
    }

    public function getWidgets(): array
    {
        return [
            KpiStats::class,               // KPI tiles at top
               // left column     
            BookingStatusTable::class,     // full width below the two-column row
            RevenueBookingsChart::class, 
            ServicesPerformance::class, 
            DailyRevenueTable::class, 
              // full width at the very bottom (ensures below calendar)
        ];
    }

    public function getColumns(): array|int
    {
        // two columns on large screens so full spans can stretch
        return [
            'sm' => 1,
            'lg' => 2,
            '2xl' => 2,
        ];
    }
}