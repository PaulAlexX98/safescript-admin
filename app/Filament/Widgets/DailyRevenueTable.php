<?php
// app/Filament/Widgets/DailyRevenueTable.php
namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables;
use Filament\Widgets\TableWidget as Base;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class DailyRevenueTable extends Base
{
    protected int|string|array $columnSpan = 1;
    protected static ?int $sort = 100;

    protected function getTableQuery(): Builder
    {
        $sumExpr = $this->sumRevenueExpr();

        // Prefer payments if present and carrying amounts
        $hasPayments = \Illuminate\Support\Facades\Schema::hasTable('payments');
        $usePayments = false;
        $paymentAmountExpr = null;
        if ($hasPayments) {
            if (Schema::hasColumn('payments', 'amount')) {
                $paymentAmountExpr = 'SUM(payments.amount)';
                $usePayments = true;
            } elseif (Schema::hasColumn('payments', 'total')) {
                $paymentAmountExpr = 'SUM(payments.total)';
                $usePayments = true;
            } elseif (Schema::hasColumn('payments', 'meta')) {
                // Try JSON amounts including pence
                $paymentAmountExpr = "SUM(COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.amount')) AS DECIMAL(12,2)),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.total')) AS DECIMAL(12,2)),
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.amount_pence')) AS DECIMAL(12,2)) / 100,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.total_pence')) AS DECIMAL(12,2)) / 100,
                    0
                ))";
                $usePayments = true;
            }
        }

        if ($usePayments && $paymentAmountExpr) {
            // Use payments created_at and sum amounts; count distinct orders as bookings when available
            $q = Order::query()->withoutGlobalScopes()
                ->leftJoin('payments', 'payments.order_id', '=', 'orders.id')
                ->whereNotNull('payments.id');

            // Only include successful payments when a status column exists
            if (Schema::hasColumn('payments', 'status')) {
                $q->whereIn('payments.status', ['paid','captured','succeeded','success','completed']);
            }

            return $q
                ->selectRaw('DATE(payments.created_at) as day')
                ->selectRaw("$paymentAmountExpr as revenue")
                ->selectRaw('COUNT(DISTINCT orders.id) as bookings')
                ->selectRaw('MIN(orders.id) as oid')
                ->groupBy('day')
                ->orderByDesc('day')
                ->orderBy('oid')
                ->limit(7);
        }

        // If we can't find any usable total on orders, fall back to summing order_items
        $useItems = $sumExpr === 'SUM(0)'
            && \Illuminate\Support\Facades\Schema::hasTable('order_items')
            && (\Illuminate\Support\Facades\Schema::hasColumn('order_items', 'total')
                || \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'line_total')
                || (\Illuminate\Support\Facades\Schema::hasColumn('order_items', 'price')
                    && \Illuminate\Support\Facades\Schema::hasColumn('order_items', 'quantity')));

        if ($useItems) {
            // Build an expression for item totals
            $itemExpr = '0';
            if (Schema::hasColumn('order_items', 'total')) {
                $itemExpr = 'SUM(order_items.total)';
            } elseif (Schema::hasColumn('order_items', 'line_total')) {
                $itemExpr = 'SUM(order_items.line_total)';
            } elseif (Schema::hasColumn('order_items', 'price') && Schema::hasColumn('order_items', 'quantity')) {
                $itemExpr = 'SUM(order_items.price * order_items.quantity)';
            }

            return Order::query()->withoutGlobalScopes()
                ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
                ->selectRaw('DATE(orders.created_at) as day')
                ->selectRaw("$itemExpr as revenue")
                ->selectRaw('COUNT(DISTINCT orders.id) as bookings')
                ->selectRaw('MIN(orders.id) as oid')
                ->groupBy('day')
                ->orderByDesc('day')
                ->orderBy('oid')
                ->limit(7);
        }

        // Default path: sum from orders table
        return Order::query()->withoutGlobalScopes()
            ->selectRaw('DATE(orders.created_at) as day')
            ->selectRaw("$sumExpr as revenue")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('MIN(orders.id) as oid')
            ->groupBy('day')
            ->orderByDesc('day')
            ->orderBy('oid')
            ->limit(7);
    }

    protected function getDefaultTableSortColumn(): ?string
    {
        return null;
    }

    protected function getDefaultTableSortDirection(): ?string
    {
        return null;
    }

    // choose the right SUM expression based on available columns
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

        // JSON meta fallbacks
        if (Schema::hasColumn($t, 'meta')) {
            // total in major units
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.grand_total")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_gbp")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_amount")) AS DECIMAL(12,2))';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.totalMinor")) AS DECIMAL(12,2)) / 100';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.selectedProduct.totalMinor")) AS DECIMAL(12,2)) / 100';
            // totals stored in pence/cents
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.total_pence")) AS DECIMAL(12,2)) / 100';
            $parts[] = 'CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, "$.amount_pence")) AS DECIMAL(12,2)) / 100';
        }

        if (empty($parts)) {
            return 'SUM(0)';
        }

        // Build SUM(COALESCE(part1, part2, ..., 0))
        $coalesce = 'COALESCE(' . implode(', ', $parts) . ', 0)';
        return 'SUM(' . $coalesce . ')';
    }

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('day')->label('Date')->date('d M Y'),
            Tables\Columns\TextColumn::make('revenue')->label('Revenue')->money('GBP', true)->alignRight(),
            Tables\Columns\TextColumn::make('bookings')->label('Bookings')->numeric()->alignRight(),
            Tables\Columns\TextColumn::make('avg')->label('Avg per booking')
                ->getStateUsing(fn ($record) => $record->revenue && $record->bookings
                    ? '£' . number_format($record->revenue / $record->bookings, 2)
                    : '£0.00')
                ->alignRight(),
        ];
    }

    public function getTableRecordKey(mixed $record): string
    {
        // use the grouped date as the unique key for the row
        return (string) ($record->day ?? $record->date ?? spl_object_hash($record));
    }
}