<?php

namespace App\Filament\Widgets;

use Carbon\CarbonPeriod;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevenueBookingsChart extends ChartWidget
{
    protected ?string $heading = 'Revenue and Bookings Analysis';
    protected int|string|array $columnSpan = 'full';

    protected function getFilters(): array
    {
        return [
            'daily' => 'Daily 7d',
            'weekly' => 'Weekly 12w',
            'monthly' => 'Monthly 12m',
            'yearly' => 'Yearly 5y',
        ];
    }

    protected function getData(): array
    {
        $filter = $this->filter ?? 'daily';

        return match ($filter) {
            'weekly'  => $this->aggregateByPeriod('week', 12),
            'monthly' => $this->aggregateByPeriod('month', 12),
            'yearly'  => $this->aggregateByPeriod('year', 5),
            default   => $this->aggregateByPeriod('day', 7),
        };
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function revenueSumForDate(string $day): float
    {
        $t = 'orders';
        if (! Schema::hasTable($t)) {
            return 0;
        }

        if (Schema::hasColumn($t, 'total')) {
            return (float) DB::table($t)->whereDate('created_at', $day)->sum('total');
        }
        if (Schema::hasColumn($t, 'grand_total')) {
            return (float) DB::table($t)->whereDate('created_at', $day)->sum('grand_total');
        }
        if (Schema::hasColumn($t, 'total_amount')) {
            return (float) DB::table($t)->whereDate('created_at', $day)->sum('total_amount');
        }
        if (Schema::hasColumn($t, 'amount')) {
            return (float) DB::table($t)->whereDate('created_at', $day)->sum('amount');
        }
        if (Schema::hasColumn($t, 'total_gbp')) {
            return (float) DB::table($t)->whereDate('created_at', $day)->sum('total_gbp');
        }

        if (Schema::hasColumn($t, 'meta')) {
            $expr = 'SUM(COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
                0
            )) as t';

            return (float) DB::table($t)
                ->whereDate('created_at', $day)
                ->selectRaw($expr)
                ->value('t');
        }

        return 0;
    }

    private function aggregateByPeriod(string $period, int $span): array
    {
        $labels = [];
        $revenue = [];
        $bookings = [];

        if ($period === 'day') {
            $days = collect(CarbonPeriod::create(now()->subDays($span - 1), now()))->map(fn ($d) => Carbon::parse($d));
            foreach ($days as $d) {
                $start = $d->copy()->startOfDay();
                $end   = $d->copy()->endOfDay();
                $labels[] = $d->format('d M');
                $revenue[] = $this->revenueSumForRange($start, $end);
                $bookings[] = $this->bookingsCountForRange($start, $end);
            }
        }

        if ($period === 'week') {
            for ($i = $span - 1; $i >= 0; $i--) {
                $start = now()->startOfWeek()->subWeeks($i);
                $end   = now()->endOfWeek()->subWeeks($i);
                $labels[] = 'W' . $start->format('W');
                $revenue[] = $this->revenueSumForRange($start, $end);
                $bookings[] = $this->bookingsCountForRange($start, $end);
            }
        }

        if ($period === 'month') {
            for ($i = $span - 1; $i >= 0; $i--) {
                $start = now()->startOfMonth()->subMonths($i);
                $end   = now()->endOfMonth()->subMonths($i);
                $labels[] = $start->format('M Y');
                $revenue[] = $this->revenueSumForRange($start, $end);
                $bookings[] = $this->bookingsCountForRange($start, $end);
            }
        }

        if ($period === 'year') {
            for ($i = $span - 1; $i >= 0; $i--) {
                $start = now()->startOfYear()->subYears($i);
                $end   = now()->endOfYear()->subYears($i);
                $labels[] = $start->format('Y');
                $revenue[] = $this->revenueSumForRange($start, $end);
                $bookings[] = $this->bookingsCountForRange($start, $end);
            }
        }

        return [
            'datasets' => [
                ['label' => 'Revenue', 'data' => $revenue],
                ['label' => 'Bookings', 'data' => $bookings],
            ],
            'labels' => $labels,
        ];
    }

    private function revenueSumForRange(Carbon $start, Carbon $end): float
    {
        $t = 'orders';
        if (! Schema::hasTable($t)) {
            return 0;
        }

        // Use a completion-like timestamp when available
        $dateCol = null;
        if (Schema::hasColumn($t, 'completed_at')) {
            $dateCol = 'completed_at';
        } elseif (Schema::hasColumn($t, 'paid_at')) {
            $dateCol = 'paid_at';
        } elseif (Schema::hasColumn($t, 'created_at')) {
            $dateCol = 'created_at';
        }

        $q = DB::table($t);

        if ($dateCol) {
            $q->whereBetween($dateCol, [$start, $end]);
        }

        // Only count completed orders (avoid inflating revenue with merely-paid/pending records)
        if (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            $q->whereIn('booking_status', ['approved', 'completed']);
        } elseif (Schema::hasColumn($t, 'payment_status')) {
            $q->where('payment_status', 'paid');
        }

        if (Schema::hasColumn($t, 'total')) {
            return (float) $q->sum('total');
        }
        if (Schema::hasColumn($t, 'grand_total')) {
            return (float) $q->sum('grand_total');
        }
        if (Schema::hasColumn($t, 'total_amount')) {
            return (float) $q->sum('total_amount');
        }
        if (Schema::hasColumn($t, 'amount')) {
            return (float) $q->sum('amount');
        }
        if (Schema::hasColumn($t, 'total_gbp')) {
            return (float) $q->sum('total_gbp');
        }

        if (Schema::hasColumn($t, 'meta')) {
            $expr = 'SUM(COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
                0
            )) as t';

            return (float) $q->selectRaw($expr)->value('t');
        }

        return 0;
    }

    private function bookingsCountForRange(Carbon $start, Carbon $end): int
    {
        $t = 'orders';
        if (! Schema::hasTable($t)) {
            return 0;
        }

        // Use an order completion-like timestamp when available so counts align with completed orders
        $dateCol = null;
        if (Schema::hasColumn($t, 'completed_at')) {
            $dateCol = 'completed_at';
        } elseif (Schema::hasColumn($t, 'approved_at')) {
            $dateCol = 'approved_at';
        } elseif (Schema::hasColumn($t, 'created_at')) {
            $dateCol = 'created_at';
        }

        $q = DB::table($t);

        if ($dateCol) {
            $q->whereBetween($dateCol, [$start, $end]);
        }

        // Match your Completed Orders list exactly
        if (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            // Fallback if status column isn't used
            $q->whereIn('booking_status', ['approved', 'completed']);
        }

        // Soft deletes
        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }
}