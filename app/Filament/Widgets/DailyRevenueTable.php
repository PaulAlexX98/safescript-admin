<?php
// app/Filament/Widgets/DailyRevenueTable.php
namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables;
use Filament\Widgets\TableWidget as Base;
use Illuminate\Database\Eloquent\Builder;
use App\Support\DatabaseSchema as Schema;
use Illuminate\Support\Facades\DB;

class DailyRevenueTable extends Base
{
    protected int|string|array $columnSpan = 1;
    protected static ?int $sort = 100;
    protected ?string $pollingInterval = null;

    protected function getTableQuery(): Builder
    {
        $sumExpr = $this->sumRevenueExpr();

        // Prefer payments if present and carrying amounts
        $hasPayments = Schema::hasTable('payments');
        $usePayments = false;
        $paymentAmountExpr = null;
        if ($hasPayments) {
            if (Schema::hasColumn('payments', 'amount')) {
                $paymentAmountExpr = 'SUM(payments.amount)';
                $usePayments = true;
            } elseif (Schema::hasColumn('payments', 'total')) {
                $paymentAmountExpr = 'SUM(payments.total)';
                $usePayments = true;
            } elseif (Schema::hasColumn('payments', 'amount_minor')) {
                $paymentAmountExpr = 'SUM(COALESCE(payments.amount_minor, 0)) / 100';
                $usePayments = true;
            } elseif (Schema::hasColumn('payments', 'meta')) {
                $paymentAmountExpr = "SUM(COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.amount_pence')) AS DECIMAL(12,2)) / 100,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(payments.meta, '$.amount')) AS DECIMAL(12,2)),
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

            if (Schema::hasColumn('payments', 'paid_at')) {
                $q->whereNotNull('payments.paid_at');
            }

            $inner = $q
                ->selectRaw('DATE(payments.paid_at) as day')
                ->selectRaw("$paymentAmountExpr as revenue")
                ->selectRaw('COUNT(DISTINCT orders.id) as bookings')
                ->selectRaw('MIN(orders.id) as id')
                ->groupBy('day');

            return Order::query()->withoutGlobalScopes()
                ->fromSub($inner, 'orders')
                ->select('day', 'revenue', 'bookings', 'id')
                ->reorder()
                ->orderByDesc('day')
                ->orderBy('id')
                ->limit(7);
        }

        // If we can't find any usable total on orders, fall back to summing order_items
        $useItems = $sumExpr === 'SUM(0)'
            && Schema::hasTable('order_items')
            && (Schema::hasColumn('order_items', 'total')
                || Schema::hasColumn('order_items', 'line_total')
                || (Schema::hasColumn('order_items', 'price')
                    && Schema::hasColumn('order_items', 'quantity')));

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

            $inner = Order::query()->withoutGlobalScopes()
                ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
                ->tap(fn (Builder $q) => $this->applyPaidOnlyFilter($q))
                ->selectRaw('DATE(orders.paid_at) as day')
                ->selectRaw("$itemExpr as revenue")
                ->selectRaw('COUNT(DISTINCT orders.id) as bookings')
                ->selectRaw('MIN(orders.id) as id')
                ->groupBy('day');

            return Order::query()->withoutGlobalScopes()
                ->fromSub($inner, 'orders')
                ->select('day', 'revenue', 'bookings', 'id')
                ->reorder()
                ->orderByDesc('day')
                ->orderBy('id')
                ->limit(7);
        }

        // Default path: sum from orders table
        $inner = Order::query()->withoutGlobalScopes()
            ->tap(fn (Builder $q) => $this->applyPaidOnlyFilter($q))
            ->when(
                Schema::hasColumn('orders', 'paid_at'),
                fn (Builder $q) => $q->whereBetween('orders.paid_at', [now()->subDays(6)->startOfDay(), now()->endOfDay()])
            )
            ->selectRaw('DATE(orders.paid_at) as day')
            ->selectRaw("$sumExpr as revenue")
            ->selectRaw('COUNT(*) as bookings')
            ->selectRaw('MIN(orders.id) as id')
            ->groupBy('day');

        return Order::query()->withoutGlobalScopes()
            ->fromSub($inner, 'orders')
            ->select('day', 'revenue', 'bookings', 'id')
            ->reorder()
            ->orderByDesc('day')
            ->orderBy('id')
            ->limit(7);
    }

    protected function getTableDefaultSortColumn(): ?string
    {
        return null;
    }

    protected function getTableDefaultSortDirection(): ?string
    {
        return null;
    }

    // choose the right SUM expression based on available columns
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

    protected function isTablePaginationEnabled(): bool
    {
        return false;
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('day')->label('Date')->date('d M Y')->sortable(false),
            Tables\Columns\TextColumn::make('revenue')->label('Revenue')->money('GBP', true)->alignRight()->sortable(false),
            Tables\Columns\TextColumn::make('bookings')->label('Bookings')->numeric()->alignRight()->sortable(false),
            Tables\Columns\TextColumn::make('avg')->label('Avg per booking')
                ->getStateUsing(fn ($record) => $record->revenue && $record->bookings
                    ? '£' . number_format($record->revenue / $record->bookings, 2)
                    : '£0.00')
                ->alignRight()
                ->sortable(false),
        ];
    }

    public function getTableRecordKey(mixed $record): string
    {
        // use the surrogate id as the unique key for the row if available, else fallback
        return (string) ($record->id ?? $record->day ?? $record->date ?? spl_object_hash($record));
    }
}
