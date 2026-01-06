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

    private function aggregateByPeriod(string $period, int $span): array
    {
        $t = 'orders';

        if (! Schema::hasTable($t)) {
            return [
                'datasets' => [
                    ['label' => 'Revenue', 'data' => []],
                    ['label' => 'Bookings', 'data' => []],
                ],
                'labels' => [],
            ];
        }

        $now = now();

        // Choose the best timestamp column to group by.
        $dateCol = null;
        if (Schema::hasColumn($t, 'completed_at')) {
            $dateCol = 'completed_at';
        } elseif (Schema::hasColumn($t, 'paid_at')) {
            $dateCol = 'paid_at';
        } elseif (Schema::hasColumn($t, 'approved_at')) {
            $dateCol = 'approved_at';
        } elseif (Schema::hasColumn($t, 'created_at')) {
            $dateCol = 'created_at';
        }

        // Build the numeric revenue expression (covers both columns and JSON meta fallbacks).
        $sumExpr = null;
        if (Schema::hasColumn($t, 'total')) {
            $sumExpr = 'SUM(total)';
        } elseif (Schema::hasColumn($t, 'grand_total')) {
            $sumExpr = 'SUM(grand_total)';
        } elseif (Schema::hasColumn($t, 'total_amount')) {
            $sumExpr = 'SUM(total_amount)';
        } elseif (Schema::hasColumn($t, 'amount')) {
            $sumExpr = 'SUM(amount)';
        } elseif (Schema::hasColumn($t, 'total_gbp')) {
            $sumExpr = 'SUM(total_gbp)';
        } elseif (Schema::hasColumn($t, 'meta')) {
            $sumExpr = 'SUM(COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_gbp")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_pence")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount_pence")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.selectedProduct.totalMinor")) AS DECIMAL(12,2)) / 100,
                0
            ))';
        } else {
            $sumExpr = '0';
        }

        // Decide grouping key expression and build the label/key series.
        $labels = [];
        $keys = [];
        $rangeStart = null;
        $rangeEnd = null;
        $keyExpr = null;

        if ($period === 'day') {
            $rangeStart = $now->copy()->subDays($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfDay();
            $keyExpr = "DATE({$dateCol})";

            for ($i = $span - 1; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i);
                $labels[] = $d->format('d M');
                $keys[] = $d->toDateString();
            }
        } elseif ($period === 'week') {
            $rangeStart = $now->copy()->startOfWeek()->subWeeks($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfWeek()->endOfDay();
            // ISO week-year key
            $keyExpr = "YEARWEEK({$dateCol}, 3)";

            for ($i = $span - 1; $i >= 0; $i--) {
                $w = $now->copy()->startOfWeek()->subWeeks($i);
                $labels[] = 'W' . $w->format('W');
                $keys[] = (string) ((int) $w->format('o') * 100 + (int) $w->format('W'));
            }
        } elseif ($period === 'month') {
            $rangeStart = $now->copy()->startOfMonth()->subMonths($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfMonth()->endOfDay();
            $keyExpr = "DATE_FORMAT({$dateCol}, '%Y-%m')";

            for ($i = $span - 1; $i >= 0; $i--) {
                $m = $now->copy()->startOfMonth()->subMonths($i);
                $labels[] = $m->format('M Y');
                $keys[] = $m->format('Y-m');
            }
        } else { // year
            $rangeStart = $now->copy()->startOfYear()->subYears($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfYear()->endOfDay();
            $keyExpr = "YEAR({$dateCol})";

            for ($i = $span - 1; $i >= 0; $i--) {
                $y = $now->copy()->startOfYear()->subYears($i);
                $labels[] = $y->format('Y');
                $keys[] = $y->format('Y');
            }
        }

        // If we somehow have no date column, return empty.
        if (! $dateCol) {
            return [
                'datasets' => [
                    ['label' => 'Revenue', 'data' => array_fill(0, count($labels), 0)],
                    ['label' => 'Bookings', 'data' => array_fill(0, count($labels), 0)],
                ],
                'labels' => $labels,
            ];
        }

        $q = DB::table($t)
            ->selectRaw("{$keyExpr} as k, COUNT(*) as bookings, {$sumExpr} as revenue")
            ->whereBetween($dateCol, [$rangeStart, $rangeEnd]);

        // Match your Completed Orders logic.
        if (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            $q->whereIn('booking_status', ['approved', 'completed']);
        } elseif (Schema::hasColumn($t, 'payment_status')) {
            $q->where('payment_status', 'paid');
        }

        // Soft deletes.
        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $rows = $q->groupBy('k')->orderBy('k')->get();

        $byKey = [];
        foreach ($rows as $r) {
            $k = (string) ($r->k ?? '');
            if ($k === '') {
                continue;
            }
            $byKey[$k] = [
                'revenue' => (float) ($r->revenue ?? 0),
                'bookings' => (int) ($r->bookings ?? 0),
            ];
        }

        $revenue = [];
        $bookings = [];
        foreach ($keys as $k) {
            $revenue[] = (float) ($byKey[(string) $k]['revenue'] ?? 0);
            $bookings[] = (int) ($byKey[(string) $k]['bookings'] ?? 0);
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