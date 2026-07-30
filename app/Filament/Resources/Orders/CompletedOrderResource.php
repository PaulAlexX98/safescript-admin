<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\CompletedOrderDetails;
use App\Filament\Resources\Orders\Pages\ListCompletedOrders;
use App\Models\Order;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CompletedOrderResource extends OrderResource
{
    protected static ?string $navigationLabel = 'Completed';

    protected static ?string $pluralLabel = 'Completed';

    protected static ?string $modelLabel = 'Completed';

    protected static string|\UnitEnum|null $navigationGroup = 'Orders';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $model = Order::class;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getNavigationBadge(): ?string
    {
        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', 'completed')
            ->reorder()
            // completed_at is populated whenever an order becomes completed.
            // Keep this ordering indexable; COALESCE(...) forced a full filesort.
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)
            // OrderResource defaults to created_at, which appended a second sort
            // and forced MySQL to filesort every completed order. The resource
            // query is already ordered by the completed-orders composite index.
            ->defaultSort(null)
            ->defaultKeySort(false)
            // Avoid an expensive full filtered COUNT(*) before returning page one.
            // Users retain Previous/Next pagination and all search functionality.
            ->paginationMode(PaginationMode::Simple)
            // Prevent several long-running Livewire requests stacking while typing.
            ->searchDebounce('900ms')
            ->splitSearchTerms(false)
            ->searchUsing(
                fn (Builder $query, string $search): Builder => static::applyCompletedOrdersSearch(
                    $query,
                    $search
                )
            );
    }

    protected static function applyCompletedOrdersSearch(
        Builder $query,
        string $search
    ): Builder {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        // Order references are the common case and have a normal B-tree index.
        // Prefix matching keeps that index usable; "%term%" did not.
        if (preg_match('/^(?:PWM|PTC)[A-Z0-9-]*$/i', $search) === 1) {
            return $query->where('orders.reference', 'like', strtoupper($search).'%');
        }

        if (str_contains($search, '@')) {
            return $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('orders.email', 'like', $search.'%')
                    ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery
                        ->where('email', 'like', $search.'%'));
            });
        }

        $tokens = array_values(array_filter(
            preg_split('/[^\pL\pN]+/u', $search) ?: [],
            fn (string $token): bool => mb_strlen($token) >= 3
        ));

        if ($tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        $booleanSearch = implode(' ', array_map(
            fn (string $token): string => '+'.$token.'*',
            $tokens
        ));

        // Keep FULLTEXT and customer matching in separate UNION branches.
        // Combining them with OR makes MariaDB abandon the FULLTEXT index.
        $metaMatches = DB::table('orders as meta_orders')
            ->select('meta_orders.id')
            ->whereRaw(
                'MATCH(meta_orders.meta) AGAINST (? IN BOOLEAN MODE)',
                [$booleanSearch]
            );

        $customerMatches = DB::table('orders as customer_orders')
            ->select('customer_orders.id')
            ->join('users as search_users', 'search_users.id', '=', 'customer_orders.user_id')
            ->where(function ($userQuery) use ($search): void {
                $userQuery
                    ->where('search_users.first_name', 'like', $search.'%')
                    ->orWhere('search_users.last_name', 'like', $search.'%')
                    ->orWhere('search_users.name', 'like', $search.'%');
            });

        return $query
            ->joinSub(
                $metaMatches->union($customerMatches),
                'search_matches',
                'search_matches.id',
                '=',
                'orders.id'
            )
            ->select('orders.*');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompletedOrders::route('/'),
            'details' => CompletedOrderDetails::route('/{record}/details'),
        ];
    }
}
