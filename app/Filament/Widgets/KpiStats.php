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
    protected ?string $pollingInterval = null;

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

        $newOrders = $this->countNewOrders($start, $end);

        // Total bookings = sum of these non-overlapping buckets
        $totalBookings = $completed + $rejected + $unpaid;

        return [
            Stat::make('Total Revenue', '£' . number_format($totalRevenue ?? 0, 2)),
            Stat::make('Total Bookings', number_format($totalBookings)),
            Stat::make('New Orders', number_format($newOrders)),
            Stat::make('Completed', number_format($completed)),
            Stat::make('Rejected', number_format($rejected)),
            Stat::make('Unpaid', number_format($unpaid)),
        ];
    }

    private function sumOrdersRevenue(?Carbon $start = null, ?Carbon $end = null): float
    {
        if (! Schema::hasTable('orders')) {
            return 0.0;
        }

        $q = DB::table('orders');

        $dateCol = $this->completedDateColumn();
        if ($start && $end && $dateCol) {
            $q->whereBetween($dateCol, [$start, $end]);
        }

        if (Schema::hasColumn('orders', 'status')) {
            $q->where('status', 'completed');
        } elseif (Schema::hasColumn('orders', 'booking_status')) {
            $q->where('booking_status', 'completed');
        }

        if (Schema::hasColumn('orders', 'products_total_minor')) {
            return (float) $q->sum(DB::raw('COALESCE(products_total_minor, 0)')) / 100;
        }

        foreach ([
            'total',
            'grand_total',
            'total_amount',
            'amount',
            'total_gbp',
            'total_inc_vat',
            'net_total',
        ] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return (float) $q->sum($column);
            }
        }

        if (! Schema::hasColumn('orders', 'meta')) {
            return 0.0;
        }

        return (float) ($q->selectRaw('SUM(COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2)),
            0
        )) as t')->value('t') ?? 0);
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

        if (Schema::hasColumn('orders', 'status')) {
            $q->whereNotIn('status', [
                'completed',
                'approved',
                'paid',
                'rejected',
                'cancelled',
                'canceled',
                'declined',
            ]);
        }

        if (Schema::hasColumn('orders', 'payment_status')) {
            $q->where('payment_status', 'unpaid');
        } elseif (Schema::hasColumn('orders', 'booking_status')) {
            $q->where('booking_status', 'unpaid');
        } elseif (Schema::hasColumn('orders', 'status')) {
            $q->where('status', 'unpaid');
        } elseif (Schema::hasColumn('orders', 'meta')) {
            $q->where(function ($w) {
                $w->whereRaw(
                    "LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payment_status'))) = ?",
                    ['unpaid']
                )->orWhereRaw(
                    "LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.payment_status_label'))) = ?",
                    ['unpaid']
                );
            });
        } else {
            return 0;
        }

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

    private function countNewOrders(?Carbon $start = null, ?Carbon $end = null): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $q = DB::table('orders');

        if ($start && $end && Schema::hasColumn('orders', 'created_at')) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        if (Schema::hasColumn('orders', 'type')) {
            $q->whereRaw('LOWER(type) = ?', ['new']);
        } elseif (Schema::hasColumn('orders', 'reference')) {
            $q->where('reference', 'REGEXP', '^PTC[A-Z]*N[0-9]{6}$');
        } elseif (Schema::hasColumn('orders', 'meta')) {
            $q->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type'))) = ?", ['new']);
        } else {
            return 0;
        }

        return (int) $q->count();
    }
}