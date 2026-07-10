<?php
// app/Filament/Widgets/ServicesPerformance.php
namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Widgets\TableWidget as Base;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

use Illuminate\Support\Facades\Schema;
use App\Models\Order;
use Carbon\Carbon;

class ServicesPerformance extends Base
{
    protected function getTableDefaultSortColumn(): ?string
    {
        return null;
    }

    protected function getTableDefaultSortDirection(): ?string
    {
        return null;
    }
    protected static ?int $sort = 100;
    protected int|string|array $columnSpan = 1;

    public string $period = 'monthly';

    private array $totalRevenueCache = [];

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('period')
                ->label('Period')
                ->icon('heroicon-o-adjustments-horizontal')
                ->form([
                    Select::make('period')
                        ->options([
                            'daily' => 'Daily 7d',
                            'weekly' => 'Weekly 12w',
                            'monthly' => 'Monthly 12m',
                        ])
                        ->default($this->period)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->period = $data['period'] ?? 'daily';

                    if (method_exists($this, 'resetTable')) {
                        $this->resetTable();
                    }

                    if (method_exists($this, 'dispatch')) {
                        $this->dispatch('$refresh');
                    }
                }),
        ];
    }

    private function getCurrentRange(): array
    {
        $filter = $this->period ?? 'daily';
        return match ($filter) {
            'weekly'  => [Carbon::now()->startOfWeek()->subWeeks(11), Carbon::now()->endOfWeek()],
            'monthly' => [Carbon::now()->startOfMonth()->subMonths(11), Carbon::now()->endOfMonth()],
            default   => [Carbon::now()->startOfDay()->subDays(6), Carbon::now()->endOfDay()],
        };
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('service')->label('Service'),
            Tables\Columns\TextColumn::make('bookings')->label('Bookings')->numeric()->alignRight(),
            Tables\Columns\TextColumn::make('revenue')->label('Revenue')->money('GBP', true)->alignRight(),
            Tables\Columns\TextColumn::make('share')
                ->label('% of total')
                ->getStateUsing(function ($record) {
                    $total = $this->totalRevenueSum();
                    $rev = (float) ($record->revenue ?? 0);
                    return $total > 0 ? number_format(($rev / $total) * 100, 1) . '%' : '0%';
                })->alignRight(),
        ];
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        if (! Schema::hasTable('orders')) {
            return Order::query()->whereRaw('1 = 0');
        }

        $sumExpr = $this->sumRevenueExpr();

        if (Schema::hasColumn('orders', 'service_slug')) {
            $serviceExpr = 'COALESCE(NULLIF(orders.service_slug, ""), "Unknown")';
        } elseif (Schema::hasColumn('orders', 'service_name')) {
            $serviceExpr = 'COALESCE(NULLIF(orders.service_name, ""), "Unknown")';
        } elseif (Schema::hasColumn('orders', 'service')) {
            $serviceExpr = 'COALESCE(NULLIF(orders.service, ""), "Unknown")';
        } else {
            $serviceExpr = 'COALESCE(
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.service_slug")), ""),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.service")), ""),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.service_name")), ""),
                NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.treatment")), ""),
                "Unknown"
            )';
        }

        [$start, $end] = $this->getCurrentRange();

        // Build the aggregated base query on orders
        $base = Order::query()->withoutGlobalScopes();

        // Only count paid orders, and use paid_at as the period anchor when available.
        $this->applyPaidOnlyFilter($base);
        if ($start && $end) {
            $this->applyPeriodFilter($base, $start, $end);
        }

        $base = $base
            ->selectRaw("$serviceExpr as service")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw("$sumExpr as revenue")
            ->selectRaw('MIN(orders.id) as min_id')
            ->selectRaw('MIN(orders.id) as id')
            ->groupBy('service');

        // Wrap the aggregation in a subquery to prevent any framework-added ORDER BY orders.id
        $outer = Order::query()
            ->fromSub($base, 'sp')
            ->select(['sp.service', 'sp.bookings', 'sp.revenue', 'sp.min_id', 'sp.id'])
            ->reorder() // clear any implicit sorts
            ->orderByDesc('sp.revenue')
            ->orderBy('sp.id')
            ->limit(10);

        \Log::debug('ServicesPerformance SQL', [
            'sql'      => $outer->toSql(),
            'bindings' => $outer->getBindings(),
        ]);

        return $outer;
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    private function applyPaidOnlyFilter(Builder $q): Builder
    {
        $hasPaymentStatus = Schema::hasColumn('orders', 'payment_status');
        $hasPaidAt = Schema::hasColumn('orders', 'paid_at');

        if ($hasPaymentStatus && $hasPaidAt) {
            return $q->where(function (Builder $w) {
                $w->where('orders.payment_status', 'paid')
                    ->orWhereNotNull('orders.paid_at');
            });
        }

        if ($hasPaymentStatus) {
            return $q->where('orders.payment_status', 'paid');
        }

        if ($hasPaidAt) {
            return $q->whereNotNull('orders.paid_at');
        }

        if (Schema::hasColumn('orders', 'meta')) {
            return $q->where(function (Builder $w) {
                $w->whereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status'))) = ?", ['paid'])
                    ->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label'))) = ?", ['paid']);
            });
        }

        return $q->whereRaw('1 = 0');
    }

    private function applyPeriodFilter(Builder $q, Carbon $start, Carbon $end): Builder
    {
        // Prefer paid_at for time series; fall back to created_at if paid_at is unavailable.
        if (Schema::hasColumn('orders', 'paid_at')) {
            return $q->whereNotNull('orders.paid_at')->whereBetween('orders.paid_at', [$start, $end]);
        }

        if (Schema::hasColumn('orders', 'created_at')) {
            return $q->whereBetween('orders.created_at', [$start, $end]);
        }

        return $q;
    }

    private function sumRevenueExpr(): string
    {
        $table = 'orders';

        if (Schema::hasColumn($table, 'products_total_minor')) {
            return 'SUM(COALESCE(orders.products_total_minor, 0)) / 100';
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
            if (Schema::hasColumn($table, $column)) {
                return 'SUM(COALESCE(orders.' . $column . ', 0))';
            }
        }

        if (! Schema::hasColumn($table, 'meta')) {
            return 'SUM(0)';
        }

        return 'SUM(COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2)),
            0
        ))';
    }

    private function totalRevenueSum(): float
    {
        $cacheKey = $this->period ?? 'daily';

        if (array_key_exists($cacheKey, $this->totalRevenueCache)) {
            return $this->totalRevenueCache[$cacheKey];
        }

        if (! Schema::hasTable('orders')) {
            return $this->totalRevenueCache[$cacheKey] = 0.0;
        }

        [$start, $end] = $this->getCurrentRange();
        $expr = $this->sumRevenueExpr() . ' as t';

        $q = Order::query()->withoutGlobalScopes()->selectRaw($expr);
        $this->applyPaidOnlyFilter($q);

        if ($start && $end) {
            $this->applyPeriodFilter($q, $start, $end);
        }

        return $this->totalRevenueCache[$cacheKey] = (float) ($q->value('t') ?? 0);
    }

    public function getTableRecordKey(mixed $record): string
    {
        return (string) ($record->service ?? $record->min_id ?? spl_object_hash($record));
    }
}