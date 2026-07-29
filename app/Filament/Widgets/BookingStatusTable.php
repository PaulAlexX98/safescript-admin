<?php

// app/Filament/Widgets/BookingStatusTable.php

namespace App\Filament\Widgets;

use App\Support\DatabaseSchema as Schema;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables;
use Filament\Widgets\TableWidget as Base;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BookingStatusTable extends Base
{
    protected static ?string $heading = 'Booking Status';

    protected static ?int $sort = 100; // ensure it renders after calendar and KPI widgets

    protected int|string|array $columnSpan = 'full'; // make the widget full-width below the calendar

    protected ?string $pollingInterval = null;

    public string $period = 'monthly';

    private function getCurrentRange(): array
    {
        $p = $this->period ?? 'monthly';
        $now = Carbon::now();

        return match ($p) {
            // Rolling windows (as labelled in the UI)
            'weekly' => [$now->copy()->subWeeks(12)->startOfDay(), $now->copy()->endOfDay()],
            'yearly' => [$now->copy()->subYears(5)->startOfDay(), $now->copy()->endOfDay()],
            default => [$now->copy()->subMonths(12)->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    private function dateColumnForStatus(string $statusKey): ?string
    {
        if ($statusKey === 'completed') {
            // Use an actual completion timestamp, not payment time
            if (Schema::hasColumn('orders', 'completed_at')) {
                return 'orders.completed_at';
            }
            if (Schema::hasColumn('orders', 'approved_at')) {
                return 'orders.approved_at';
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                return 'orders.updated_at';
            }

            return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
        }
        if ($statusKey === 'rejected') {
            if (Schema::hasColumn('orders', 'rejected_at')) {
                return 'orders.rejected_at';
            }
            if (Schema::hasColumn('orders', 'updated_at')) {
                return 'orders.updated_at';
            }

            return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
        }

        // unpaid and others
        return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
    }

    protected function getTableHeaderActions(): array
    {
        return [
            Action::make('period')
                ->label('Period')
                ->icon('heroicon-o-adjustments-horizontal')
                ->form([
                    Select::make('period')
                        ->options([
                            'weekly' => 'Weekly 12w',
                            'monthly' => 'Monthly 12m',
                            'yearly' => 'Yearly 5y',
                        ])
                        ->default($this->period)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->period = $data['period'] ?? 'monthly';

                    // Force the table to refresh after changing the period
                    if (method_exists($this, 'resetTable')) {
                        $this->resetTable();
                    }

                    // Livewire refresh as an extra nudge
                    if (method_exists($this, 'dispatch')) {
                        $this->dispatch('$refresh');
                    }
                }),
        ];
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('label')
                ->label('Status')
                ->sortable(false)
                ->searchable(false),

            Tables\Columns\TextColumn::make('count')
                ->label('Count')
                ->numeric()
                ->sortable(false)
                ->alignRight(),

            Tables\Columns\TextColumn::make('percent')
                ->label('Percent')
                ->state(fn ($record) => number_format(($record['percent'] ?? 0), 1).'%')
                ->alignRight(),

            Tables\Columns\TextColumn::make('impact')
                ->label('Impact')
                ->state(fn ($record) => isset($record['impact']) && $record['impact'] !== null
                    ? '£'.number_format((float) $record['impact'], 2)
                    : '—'
                )
                ->alignRight(),
        ];
    }

    public function getTableRecords(): Collection
    {
        $statusMap = [
            ['label' => 'Completed', 'key' => 'completed'],
            ['label' => 'Rejected',  'key' => 'rejected'],
            ['label' => 'Unpaid',    'key' => 'unpaid'],
        ];

        $metrics = Cache::flexible(
            'admin:booking-status:v3:'.($this->period ?? 'monthly'),
            [300, 86400],
            fn (): array => $this->loadStatusMetrics(),
            ['seconds' => 30]
        );

        $counts = collect($statusMap)->mapWithKeys(
            fn (array $status): array => [
                $status['key'] => (int) ($metrics[$status['key']]['count'] ?? 0),
            ]
        );
        $total = max(1, $counts->sum());

        $rows = collect($statusMap)->map(function ($s) use ($counts, $metrics, $total) {
            $key = $s['key'];
            $count = $counts[$key] ?? 0;

            return [
                'label' => $s['label'],
                'count' => $count,
                'percent' => $count > 0 ? ($count * 100 / $total) : 0,
                'impact' => (float) ($metrics[$key]['revenue'] ?? 0),
            ];
        })->values()->all();

        return collect($rows);
    }

    public function warmCache(): void
    {
        $this->getTableRecords();
    }

    /**
     * @return array<string, array{count: int, revenue: float}>
     */
    protected function loadStatusMetrics(): array
    {
        $empty = [
            'completed' => ['count' => 0, 'revenue' => 0.0],
            'rejected' => ['count' => 0, 'revenue' => 0.0],
            'unpaid' => ['count' => 0, 'revenue' => 0.0],
        ];

        if (! Schema::hasTable('orders')) {
            return $empty;
        }

        [$start, $end] = $this->getCurrentRange();
        $revenue = $this->revenueValueExpression();
        foreach (array_keys($empty) as $status) {
            [$predicate, $predicateBindings] = $this->statusMetricPredicate(
                $status,
                $start,
                $end
            );

            // Keep each status predicate in WHERE so MySQL can use its
            // status/date index instead of scanning every order.
            $row = DB::table('orders')
                ->whereRaw($predicate, $predicateBindings)
                ->selectRaw("COUNT(*) AS aggregate_count, SUM({$revenue}) AS aggregate_revenue")
                ->first();

            $empty[$status] = [
                'count' => (int) ($row->aggregate_count ?? 0),
                'revenue' => (float) ($row->aggregate_revenue ?? 0),
            ];
        }

        return $empty;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function statusMetricPredicate(string $status, Carbon $start, Carbon $end): array
    {
        $parts = [];
        $bindings = [];
        $dateColumn = $this->dateColumnForStatus($status);

        if ($dateColumn) {
            $parts[] = "{$dateColumn} BETWEEN ? AND ?";
            $bindings[] = $start;
            $bindings[] = $end;
        }

        if ($status === 'completed') {
            if (Schema::hasColumn('orders', 'status')) {
                $parts[] = 'orders.status = ?';
                $bindings[] = 'completed';
            } elseif (Schema::hasColumn('orders', 'booking_status')) {
                $parts[] = "orders.booking_status IN ('approved', 'completed')";
            }
        } elseif ($status === 'rejected') {
            $rejected = [];

            if (Schema::hasColumn('orders', 'booking_status')) {
                $rejected[] = 'orders.booking_status = ?';
                $bindings[] = 'rejected';
            }
            if (Schema::hasColumn('orders', 'status')) {
                $rejected[] = "orders.status IN ('rejected', 'cancelled', 'canceled', 'declined')";
            }

            $parts[] = $rejected ? '('.implode(' OR ', $rejected).')' : '0 = 1';
        } elseif ($status === 'unpaid') {
            if (Schema::hasColumn('orders', 'payment_status')) {
                $parts[] = 'orders.payment_status = ?';
                $bindings[] = 'unpaid';
            } elseif (Schema::hasColumn('orders', 'booking_status')) {
                $parts[] = 'orders.booking_status = ?';
                $bindings[] = 'unpaid';
            } elseif (Schema::hasColumn('orders', 'status')) {
                $parts[] = 'orders.status = ?';
                $bindings[] = 'unpaid';
            } elseif (Schema::hasColumn('orders', 'meta')) {
                $parts[] = "(LOWER(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status'))) = ?"
                    ." OR LOWER(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, '$.payment_status_label'))) = ?)";
                $bindings[] = 'unpaid';
                $bindings[] = 'unpaid';
            }

            if (Schema::hasColumn('orders', 'status')) {
                $parts[] = "orders.status NOT IN ('completed', 'approved', 'paid', 'rejected', 'cancelled', 'canceled', 'declined')";
            }
        }

        return [$parts ? implode(' AND ', $parts) : '1 = 1', $bindings];
    }

    private function revenueValueExpression(): string
    {
        if (Schema::hasColumn('orders', 'products_total_minor')) {
            return 'COALESCE(orders.products_total_minor, 0) / 100';
        }

        foreach (['total', 'grand_total', 'total_amount', 'amount', 'total_gbp'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                return "COALESCE(orders.{$column}, 0)";
            }
        }

        if (! Schema::hasColumn('orders', 'meta')) {
            return '0';
        }

        return 'COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, "$.total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, "$.grand_total")) AS DECIMAL(12,2)),
            CAST(JSON_UNQUOTE(JSON_EXTRACT(orders.meta, "$.amount")) AS DECIMAL(12,2)),
            0
        )';
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        return null;
    }

    protected function getTableContentGrid(): ?array
    {
        return ['md' => 1];
    }

    public function getTableRecordKey(mixed $record): string
    {
        if (is_array($record) && isset($record['label'])) {
            return 'booking-status-'.\Illuminate\Support\Str::slug((string) $record['label']);
        }

        return 'booking-status-'.md5(json_encode($record));
    }
}
