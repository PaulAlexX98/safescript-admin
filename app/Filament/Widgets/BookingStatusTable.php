<?php
// app/Filament/Widgets/BookingStatusTable.php
namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Widgets\TableWidget as Base;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Carbon\Carbon;

class BookingStatusTable extends Base
{
    protected static ?string $heading = 'Booking Status';

    protected static ?int $sort = 100; // ensure it renders after calendar and KPI widgets

    protected int|string|array $columnSpan = 'full'; // make the widget full-width below the calendar

    public string $period = 'monthly';

    private function getCurrentRange(): array
    {
        $p = $this->period ?? 'monthly';
        return match ($p) {
            'weekly'  => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()],
            'yearly'  => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            default   => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
        };
    }

    private function dateColumnForStatus(string $statusKey): ?string
    {
        if ($statusKey === 'completed') {
            if (Schema::hasColumn('orders', 'paid_at')) return 'orders.paid_at';
            return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
        }
        if ($statusKey === 'rejected') {
            if (Schema::hasColumn('orders', 'rejected_at')) return 'orders.rejected_at';
            if (Schema::hasColumn('orders', 'updated_at')) return 'orders.updated_at';
            return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
        }
        // unpaid and others
        return Schema::hasColumn('orders', 'created_at') ? 'orders.created_at' : null;
    }

    private function applyOrderStatusFilter(\Illuminate\Database\Query\Builder $q, string $statusKey): void
    {
        // Strict groups. Prefer booking_status when present. No pending states included.
        if ($statusKey === 'completed') {
            $q->where(function ($w) {
                // Only truly completed or paid
                if (Schema::hasColumn('orders', 'booking_status')) {
                    $w->orWhere('orders.booking_status', 'completed');
                }
                if (Schema::hasColumn('orders', 'payment_status')) {
                    $w->orWhere('orders.payment_status', 'paid');
                }
                if (Schema::hasColumn('orders', 'status')) {
                    $w->orWhereIn('orders.status', ['completed', 'fulfilled']);
                }
            });
            return;
        }

        if ($statusKey === 'rejected') {
            $q->where(function ($w) {
                if (Schema::hasColumn('orders', 'booking_status')) {
                    $w->orWhere('orders.booking_status', 'rejected');
                }
                if (Schema::hasColumn('orders', 'status')) {
                    $w->orWhereIn('orders.status', ['rejected','cancelled','canceled','declined']);
                }
            });
            return;
        }

        if ($statusKey === 'unpaid') {
            $q->where(function ($w) {
                if (Schema::hasColumn('orders', 'booking_status')) {
                    // Only explicit unpaid; DO NOT include pending approval
                    $w->orWhere('orders.booking_status', 'unpaid');
                }
                if (Schema::hasColumn('orders', 'payment_status')) {
                    $w->orWhere('orders.payment_status', 'unpaid');
                }
                if (Schema::hasColumn('orders', 'status')) {
                    $w->orWhere('orders.status', 'unpaid');
                }
            });
            return;
        }

        // Fallback exact match on orders.status if nothing else applies
        if (Schema::hasColumn('orders', 'status')) {
            $q->where('orders.status', $statusKey);
        }
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
                ->state(fn ($record) => number_format(($record['percent'] ?? 0), 1) . '%')
                ->alignRight(),

            Tables\Columns\TextColumn::make('impact')
                ->label('Impact')
                ->state(fn ($record) => isset($record['impact']) && $record['impact'] !== null
                    ? '£' . number_format((float) $record['impact'], 2)
                    : '—'
                )
                ->alignRight(),
        ];
    }

    public function getTableRecords(): Collection
    {
        [$start, $end] = $this->getCurrentRange();

        $statusMap = [
            ['label' => 'Completed', 'key' => 'completed'],
            ['label' => 'Rejected',  'key' => 'rejected'],
            ['label' => 'Unpaid',    'key' => 'unpaid'],
        ];

        // Count orders by status within the selected period using the right date column per status
        $counts = collect($statusMap)->mapWithKeys(function ($s) use ($start, $end) {
            $key = $s['key'];
            $q = DB::table('orders');

            // choose date column per status
            $dateCol = $this->dateColumnForStatus($key);
            if ($dateCol && $start && $end) {
                $q->whereBetween($dateCol, [$start, $end]);
            }

            if ($key === 'completed') {
                // Your original strict rule that was correct before
                $q->where('status', 'completed');
            } else {
                // Use normalised mapping for rejected and unpaid
                $this->applyOrderStatusFilter($q, $key);
            }

            return [$key => (int) $q->count()];
        });

        $total = max(1, $counts->sum());

        // Impact by order status within the same period
        $impactByStatus = [
            'completed' => $this->sumOrdersRevenue($start, $end, 'completed'),
            'rejected'  => $this->sumOrdersRevenue($start, $end, 'rejected'),
            'unpaid'    => $this->sumOrdersRevenue($start, $end, 'unpaid'),
        ];

        $rows = collect($statusMap)->map(function ($s) use ($counts, $impactByStatus, $total) {
            $key = $s['key'];
            $count = $counts[$key] ?? 0;
            return [
                'label' => $s['label'],
                'count' => $count,
                'percent' => $count > 0 ? ($count * 100 / $total) : 0,
                'impact' => $impactByStatus[$key] ?? null,
            ];
        })->values()->all();

        return collect($rows);
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

    private function sumOrdersRevenue(?Carbon $start = null, ?Carbon $end = null, ?string $statusKey = null): float
    {
        $t = 'orders';
        if (! Schema::hasTable($t)) {
            return 0.0;
        }

        // direct numeric columns if present
        foreach (['total','grand_total','total_amount','amount','total_gbp'] as $col) {
            if (Schema::hasColumn($t, $col)) {
                $q = DB::table($t);
                $dateCol = $statusKey ? $this->dateColumnForStatus($statusKey) : (Schema::hasColumn($t, 'created_at') ? 'orders.created_at' : null);
                if ($start && $end && $dateCol) {
                    $q->whereBetween($dateCol, [$start, $end]);
                }
                if ($statusKey !== null) {
                    $this->applyOrderStatusFilter($q, $statusKey);
                }
                return (float) $q->sum($col);
            }
        }

        // JSON meta fallback including minor-unit totals
        if (Schema::hasColumn($t, 'meta')) {
            $expr = 'SUM(COALESCE(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_gbp")) AS DECIMAL(12,2)),
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.selectedProduct.totalMinor")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_pence")) AS DECIMAL(12,2)) / 100,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount_pence")) AS DECIMAL(12,2)) / 100,
                0
            )) as t';

            $q = DB::table($t);
            $dateCol = $statusKey ? $this->dateColumnForStatus($statusKey) : (Schema::hasColumn($t, 'created_at') ? 'orders.created_at' : null);
            if ($start && $end && $dateCol) {
                $q->whereBetween($dateCol, [$start, $end]);
            }
            if ($statusKey !== null) {
                $this->applyOrderStatusFilter($q, $statusKey);
            }

            return (float) $q->selectRaw($expr)->value('t');
        }

        return 0.0;
    }
    public function getTableRecordKey(mixed $record): string
    {
        if (is_array($record) && isset($record['label'])) {
            return 'booking-status-' . \Illuminate\Support\Str::slug((string) $record['label']);
        }

        return 'booking-status-' . md5(json_encode($record));
    }
}