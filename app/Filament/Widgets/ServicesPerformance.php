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
    protected static ?int $sort = 100;
    protected int|string|array $columnSpan = 1;

    public string $period = 'monthly';

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

        $serviceExpr = 'COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.service")), ""),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.service_name")), ""),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.treatment")), ""),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.treatment_name")), ""),
            "Unknown"
        )';

        [$start, $end] = $this->getCurrentRange();

        $query = Order::query();

        if (Schema::hasColumn('orders', 'created_at') && $start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Avoid ONLY_FULL_GROUP_BY errors by not ordering by non-grouped columns.
        return $query
            ->selectRaw("$serviceExpr as service")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw("$sumExpr as revenue")
            ->selectRaw('MIN(orders.id) as id')
            ->groupBy('service')
            ->reorder()
            ->orderByDesc('revenue')
            ->orderBy('id')
            ->limit(10);
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    private function sumRevenueExpr(): string
    {
        $t = 'orders';
        $parts = [];

        // common numeric columns
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

        // JSON meta fallbacks including minor units
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
            return 'SUM(0)';
        }

        $coalesce = 'COALESCE(' . implode(', ', $parts) . ', 0)';
        return 'SUM(' . $coalesce . ')';
    }

    private function totalRevenueSum(): float
    {
        $t = 'orders';
        if (! Schema::hasTable($t)) return 0.0;

        [$start, $end] = $this->getCurrentRange();
        $expr = $this->sumRevenueExpr() . ' as t';

        $q = Order::query()->selectRaw($expr);
        if (Schema::hasColumn($t, 'created_at') && $start && $end) {
            $q->whereBetween('created_at', [$start, $end]);
        }

        return (float) ($q->value('t') ?? 0);
    }

    public function getTableRecordKey(mixed $record): string
    {
        return (string) ($record->service ?? $record->id ?? spl_object_hash($record));
    }
}