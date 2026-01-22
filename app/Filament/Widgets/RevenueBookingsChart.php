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

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => "function(value){ return '£' + value; }",
                    ],
                ],
                'y1' => [
                    'beginAtZero' => true,
                    'position' => 'right',
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
                'tooltip' => [
                    'enabled' => true,
                ],
            ],
        ];
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

        // Prefer aggregating from payments if available (matches the working revenue table logic).
        $paymentsTable = null;
        foreach (['payments', 'order_payments'] as $pt) {
            if (Schema::hasTable($pt)) {
                $paymentsTable = $pt;
                break;
            }
        }

        if ($paymentsTable) {
            return $this->aggregatePaymentsByPeriod($paymentsTable, $period, $span, $now);
        }

        // Choose the best timestamp expression to group by.
        // Some records may have completed_at null even when status is completed.
        $dateCols = [];
        foreach (['completed_at', 'paid_at', 'approved_at', 'created_at'] as $c) {
            if (Schema::hasColumn($t, $c)) {
                $dateCols[] = $c;
            }
        }

        $dateExpr = null;
        if (! empty($dateCols)) {
            $dateExpr = count($dateCols) === 1
                ? $dateCols[0]
                : 'COALESCE(' . implode(', ', $dateCols) . ')';
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
            $keyExpr = "DATE({$dateExpr})";

            for ($i = $span - 1; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i);
                $labels[] = $d->format('d M');
                $keys[] = $d->toDateString();
            }
        } elseif ($period === 'week') {
            $rangeStart = $now->copy()->startOfWeek()->subWeeks($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfWeek()->endOfDay();
            // ISO week-year key
            $keyExpr = "YEARWEEK({$dateExpr}, 3)";

            for ($i = $span - 1; $i >= 0; $i--) {
                $w = $now->copy()->startOfWeek()->subWeeks($i);
                $labels[] = 'W' . $w->format('W');
                $keys[] = (string) ((int) $w->format('o') * 100 + (int) $w->format('W'));
            }
        } elseif ($period === 'month') {
            $rangeStart = $now->copy()->startOfMonth()->subMonths($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfMonth()->endOfDay();
            $keyExpr = "DATE_FORMAT({$dateExpr}, '%Y-%m')";

            for ($i = $span - 1; $i >= 0; $i--) {
                $m = $now->copy()->startOfMonth()->subMonths($i);
                $labels[] = $m->format('M Y');
                $keys[] = $m->format('Y-m');
            }
        } else { // year
            $rangeStart = $now->copy()->startOfYear()->subYears($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfYear()->endOfDay();
            $keyExpr = "YEAR({$dateExpr})";

            for ($i = $span - 1; $i >= 0; $i--) {
                $y = $now->copy()->startOfYear()->subYears($i);
                $labels[] = $y->format('Y');
                $keys[] = $y->format('Y');
            }
        }

        // If we somehow have no date expression, return empty.
        if (! $dateExpr) {
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
            ->whereBetween(DB::raw($dateExpr), [$rangeStart, $rangeEnd]);

        // Filter logic: prefer paid orders so revenue/booking charts reflect real sales activity.
        // Many paid orders may still be `status = pending` until reviewed, which would otherwise show as zero.
        if (Schema::hasColumn($t, 'payment_status')) {
            $q->where('payment_status', 'paid');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            $q->whereIn('booking_status', ['approved', 'completed']);
        } elseif (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
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
                [
                    'label' => 'Revenue',
                    'data' => $revenue,
                    'yAxisID' => 'y',
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Bookings',
                    'data' => $bookings,
                    'yAxisID' => 'y1',
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                    'borderWidth' => 2,
                    'borderDash' => [6, 4],
                ],
            ],
            'labels' => $labels,
        ];
    }

    private function aggregatePaymentsByPeriod(string $pt, string $period, int $span, Carbon $now): array
    {
        $orders = 'orders';

        // Build labels/keys and date range
        $labels = [];
        $keys = [];
        $rangeStart = null;
        $rangeEnd = null;
        $keyExpr = null;

        if ($period === 'day') {
            $rangeStart = $now->copy()->subDays($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfDay();
            $keyExpr = "DATE({$pt}.created_at)";

            for ($i = $span - 1; $i >= 0; $i--) {
                $d = $now->copy()->subDays($i);
                $labels[] = $d->format('d M');
                $keys[] = $d->toDateString();
            }
        } elseif ($period === 'week') {
            $rangeStart = $now->copy()->startOfWeek()->subWeeks($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfWeek()->endOfDay();
            $keyExpr = "YEARWEEK({$pt}.created_at, 3)";

            for ($i = $span - 1; $i >= 0; $i--) {
                $w = $now->copy()->startOfWeek()->subWeeks($i);
                $labels[] = 'W' . $w->format('W');
                $keys[] = (string) ((int) $w->format('o') * 100 + (int) $w->format('W'));
            }
        } elseif ($period === 'month') {
            $rangeStart = $now->copy()->startOfMonth()->subMonths($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfMonth()->endOfDay();
            $keyExpr = "DATE_FORMAT({$pt}.created_at, '%Y-%m')";

            for ($i = $span - 1; $i >= 0; $i--) {
                $m = $now->copy()->startOfMonth()->subMonths($i);
                $labels[] = $m->format('M Y');
                $keys[] = $m->format('Y-m');
            }
        } else { // year
            $rangeStart = $now->copy()->startOfYear()->subYears($span - 1)->startOfDay();
            $rangeEnd = $now->copy()->endOfYear()->endOfDay();
            $keyExpr = "YEAR({$pt}.created_at)";

            for ($i = $span - 1; $i >= 0; $i--) {
                $y = $now->copy()->startOfYear()->subYears($i);
                $labels[] = $y->format('Y');
                $keys[] = $y->format('Y');
            }
        }

        // Revenue expression from payments
        $sumExpr = null;
        if (Schema::hasColumn($pt, 'amount_minor')) {
            $sumExpr = 'SUM(' . $pt . '.amount_minor) / 100';
        } elseif (Schema::hasColumn($pt, 'total_minor')) {
            $sumExpr = 'SUM(' . $pt . '.total_minor) / 100';
        } elseif (Schema::hasColumn($pt, 'amount')) {
            $sumExpr = 'SUM(' . $pt . '.amount)';
        } elseif (Schema::hasColumn($pt, 'meta')) {
            $sumExpr = 'SUM(COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $pt . '.meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $pt . '.meta, "$.amount_minor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(' . $pt . '.meta, "$.amount")) AS DECIMAL(12,2)),
                0
            ))';
        } else {
            $sumExpr = '0';
        }

        // Bookings should be distinct orders (if possible)
        $bookingsExpr = null;
        if (Schema::hasColumn($pt, 'order_id')) {
            $bookingsExpr = 'COUNT(DISTINCT ' . $pt . '.order_id)';
        } elseif (Schema::hasColumn($pt, 'order_reference')) {
            $bookingsExpr = 'COUNT(DISTINCT ' . $pt . '.order_reference)';
        } else {
            $bookingsExpr = 'COUNT(*)';
        }

        $q = DB::table($pt)
            ->selectRaw("{$keyExpr} as k, {$bookingsExpr} as bookings, {$sumExpr} as revenue")
            ->whereBetween($pt . '.created_at', [$rangeStart, $rangeEnd]);

        // Join orders when we can, so we can reuse existing paid filters if needed
        if (Schema::hasTable($orders)) {
            if (Schema::hasColumn($pt, 'order_id')) {
                $q->leftJoin($orders, $orders . '.id', '=', $pt . '.order_id');
            } elseif (Schema::hasColumn($pt, 'order_reference') && Schema::hasColumn($orders, 'reference')) {
                $q->leftJoin($orders, $orders . '.reference', '=', $pt . '.order_reference');
            }
        }

        // Filter: prefer payment record status if present, otherwise fall back to orders.payment_status
        if (Schema::hasColumn($pt, 'status')) {
            $q->whereIn(DB::raw('LOWER(' . $pt . '.status)'), ['paid', 'succeeded', 'captured', 'approved']);
        } elseif (Schema::hasColumn($pt, 'payment_status')) {
            $q->where(DB::raw('LOWER(' . $pt . '.payment_status)'), 'paid');
        } elseif (Schema::hasTable($orders) && Schema::hasColumn($orders, 'payment_status')) {
            $q->where(DB::raw('LOWER(' . $orders . '.payment_status)'), 'paid');
        }

        $rows = $q->groupBy('k')->orderBy('k')->get();

        $byKey = [];
        foreach ($rows as $r) {
            $k = (string) ($r->k ?? '');
            if ($k === '') continue;
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
                [
                    'label' => 'Revenue',
                    'data' => $revenue,
                    'yAxisID' => 'y',
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Bookings',
                    'data' => $bookings,
                    'yAxisID' => 'y1',
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                    'borderWidth' => 2,
                    'borderDash' => [6, 4],
                ],
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

        // Use a completion-like timestamp expression when available
        $dateCols = [];
        foreach (['completed_at', 'paid_at', 'approved_at', 'created_at'] as $c) {
            if (Schema::hasColumn($t, $c)) {
                $dateCols[] = $c;
            }
        }

        $dateExpr = null;
        if (! empty($dateCols)) {
            $dateExpr = count($dateCols) === 1
                ? $dateCols[0]
                : 'COALESCE(' . implode(', ', $dateCols) . ')';
        }

        $q = DB::table($t);

        if ($dateExpr) {
            $q->whereBetween(DB::raw($dateExpr), [$start, $end]);
        }

        // Prefer paid orders so revenue totals match actual payments.
        if (Schema::hasColumn($t, 'payment_status')) {
            $q->where('payment_status', 'paid');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            $q->whereIn('booking_status', ['approved', 'completed']);
        } elseif (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
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
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_gbp")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_pence")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount_pence")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.selectedProduct.totalMinor")) AS DECIMAL(12,2)) / 100,
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

        // Use an order completion-like timestamp expression when available so counts align with completed orders
        $dateCols = [];
        foreach (['completed_at', 'paid_at', 'approved_at', 'created_at'] as $c) {
            if (Schema::hasColumn($t, $c)) {
                $dateCols[] = $c;
            }
        }

        $dateExpr = null;
        if (! empty($dateCols)) {
            $dateExpr = count($dateCols) === 1
                ? $dateCols[0]
                : 'COALESCE(' . implode(', ', $dateCols) . ')';
        }

        $q = DB::table($t);

        if ($dateExpr) {
            $q->whereBetween(DB::raw($dateExpr), [$start, $end]);
        }

        // Prefer paid orders so booking counts align with real activity.
        if (Schema::hasColumn($t, 'payment_status')) {
            $q->where('payment_status', 'paid');
        } elseif (Schema::hasColumn($t, 'booking_status')) {
            $q->whereIn('booking_status', ['approved', 'completed']);
        } elseif (Schema::hasColumn($t, 'status')) {
            $q->where('status', 'completed');
        }

        // Soft deletes
        if (Schema::hasColumn($t, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (int) $q->count();
    }
}