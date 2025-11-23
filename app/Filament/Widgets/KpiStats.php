<?php
// app/Filament/Widgets/KpiStats.php
namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as Base;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class KpiStats extends Base
{
    protected static ?int $sort = 10;
    protected int|string|array $columnSpan = 'full';

    protected function getFilters(): array
    {
        return [
            'all' => 'All time',
            'month' => 'This month',
            'year' => 'This year',
        ];
    }

    protected function getStats(): array
    {
        $filter = $this->filter ?? 'all';

        $range = match ($filter) {
            'month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'year'  => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            default => [null, null],
        };

        [$start, $end] = $range;

        $totalRevenue   = $this->sumOrdersRevenue($start, $end);

        // Completed / Rejected / Unpaid counts within range
        $completed = $this->countCompletedOrders($start, $end);
        $rejected  = $this->countRejectedOrders($start, $end);
        $unpaid    = $this->countUnpaidOrders($start, $end);

        // Total bookings equals the sum of these non-overlapping buckets
        $totalBookings = $completed + $rejected + $unpaid;

        return [
            Stat::make('Total Revenue', '£' . number_format($totalRevenue ?? 0, 2)),
            Stat::make('Total Bookings', number_format($totalBookings)),
            Stat::make('Completed', number_format($completed)),
            Stat::make('Unpaid', number_format($unpaid)),
        ];
    }

    private function sumOrdersRevenue(?Carbon $start = null, ?Carbon $end = null): float
    {
        $t = 'orders';
        $parts = [];

        foreach ([
            'total',
            'grand_total',
            'total_amount',
            'amount',
            'total_gbp',
            'total_inc_vat',
            'net_total',
        ] as $col) {
            if (Schema::hasColumn($t, $col)) {
                $parts[] = $col;
            }
        }

        if (Schema::hasColumn($t, 'meta')) {
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_gbp")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_amount")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.selectedProduct.totalMinor")) AS DECIMAL(12,2)) / 100';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_pence")) AS DECIMAL(12,2)) / 100';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount_pence")) AS DECIMAL(12,2)) / 100';
        }

        if (empty($parts)) {
            return 0.0;
        }

        $coalesce = 'COALESCE(' . implode(', ', $parts) . ', 0)';
        $q = DB::table($t)->selectRaw('SUM(' . $coalesce . ') as t');

        if ($start && $end && Schema::hasColumn($t, 'created_at')) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        return (float) ($q->value('t') ?? 0);
    }

    private function countUnpaidOrders(?Carbon $start = null, ?Carbon $end = null): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $q = DB::table('orders');

        if ($start && $end && Schema::hasColumn('orders', 'created_at')) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        $q->where(function ($w) {
            if (Schema::hasColumn('orders', 'booking_status')) {
                $w->orWhere('booking_status', 'unpaid');
            }
            if (Schema::hasColumn('orders', 'payment_status')) {
                $w->orWhere('payment_status', 'unpaid');
            }
            if (Schema::hasColumn('orders', 'status')) {
                $w->orWhere('status', 'unpaid');
            }
        });

        return (int) $q->count();
    }

    private function completedDateColumn(): ?string
    {
        if (Schema::hasColumn('orders', 'approved_at')) return 'orders.approved_at';
        if (Schema::hasColumn('orders', 'paid_at')) return 'orders.paid_at';
        return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
    }

    private function rejectedDateColumn(): ?string
    {
        if (Schema::hasColumn('orders', 'rejected_at')) return 'orders.rejected_at';
        if (Schema::hasColumn('orders', 'updated_at')) return 'orders.updated_at';
        return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
    }

    private function countCompletedOrders(?Carbon $start = null, ?Carbon $end = null): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $q = DB::table('orders');

        $dateCol = $this->completedDateColumn();
        if ($start && $end && $dateCol) {
            $q->whereBetween($dateCol, [$start, $end]);
        }

        // Match BookingStatusTable: strict completed state
        if (Schema::hasColumn('orders', 'status')) {
            $q->where('status', 'completed');
        } else {
            // Fallback: booking_status completed
            if (Schema::hasColumn('orders', 'booking_status')) {
                $q->where('booking_status', 'completed');
            }
        }

        return (int) $q->count();
    }

    private function countRejectedOrders(?Carbon $start = null, ?Carbon $end = null): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $q = DB::table('orders');

        $dateCol = $this->rejectedDateColumn();
        if ($start && $end && $dateCol) {
            $q->whereBetween($dateCol, [$start, $end]);
        }

        $q->where(function ($w) {
            if (Schema::hasColumn('orders', 'booking_status')) {
                $w->orWhere('booking_status', 'rejected');
            }
            if (Schema::hasColumn('orders', 'status')) {
                $w->orWhereIn('status', ['rejected','cancelled','canceled','declined']);
            }
        });

        return (int) $q->count();
    }
}