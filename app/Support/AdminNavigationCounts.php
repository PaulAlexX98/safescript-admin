<?php

namespace App\Support;

use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;
use App\Filament\Resources\Orders\CompletedOrderResource;
use App\Filament\Resources\PendingOrders\PendingOrderResource;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AdminNavigationCounts
{
    public const CACHE_KEY = 'filament:navigation:private-order-counts:v2';

    private const FRESH_SECONDS = 300;

    private const STALE_SECONDS = 86400;

    /** @var array{pending: int, approved: int, completed: int}|null */
    private static ?array $requestCounts = null;

    /**
     * Return cached counts immediately. Once stale, Laravel refreshes them
     * after the response so navigation rendering never waits for count SQL.
     *
     * @return array{pending: int, approved: int, completed: int}
     */
    public static function all(): array
    {
        return self::$requestCounts ??= Cache::flexible(
            self::CACHE_KEY,
            [self::FRESH_SECONDS, self::STALE_SECONDS],
            fn (): array => self::queryCounts(),
            ['seconds' => 30]
        );
    }

    /**
     * Warm the cache during deployment, avoiding a synchronous cold-cache
     * refresh on the first admin request.
     *
     * @return array{pending: int, approved: int, completed: int}
     */
    public static function refresh(): array
    {
        $counts = self::queryCounts();

        Cache::putMany([
            self::CACHE_KEY => $counts,
            'illuminate:cache:flexible:created:'.self::CACHE_KEY => now()->getTimestamp(),
        ], self::STALE_SECONDS);

        return self::$requestCounts = $counts;
    }

    /**
     * @return array{pending: int, approved: int, completed: int}
     */
    private static function queryCounts(): array
    {
        return [
            'pending' => self::countSafely(
                fn (): int => PendingOrderResource::getEloquentQuery()
                    ->reorder()
                    ->count()
            ),
            'approved' => self::countSafely(
                fn (): int => ApprovedOrderResource::getEloquentQuery()
                    ->reorder()
                    ->count()
            ),
            'completed' => self::countSafely(
                fn (): int => CompletedOrderResource::getEloquentQuery()
                    ->reorder()
                    ->count()
            ),
        ];
    }

    private static function countSafely(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Test helper for resetting only process-local state.
     */
    public static function flushRequestCache(): void
    {
        self::$requestCounts = null;
    }
}
