<?php

namespace Tests\Unit;

use App\Filament\Resources\PendingOrders\PendingOrderResource;
use PHPUnit\Framework\TestCase;

class PendingOrderResourcePerformanceTest extends TestCase
{
    public function test_review_banner_is_resolved_once_per_record_instance(): void
    {
        $record = (object) ['banner' => 'Review due'];

        $this->assertSame('Review due', TestablePendingOrderResource::banner($record));
        $this->assertSame('Review due', TestablePendingOrderResource::banner($record));
        $this->assertSame(1, TestablePendingOrderResource::$resolutionCount);
    }

    public function test_empty_review_banner_is_also_cached(): void
    {
        $record = (object) ['banner' => null];
        $before = TestablePendingOrderResource::$resolutionCount;

        $this->assertNull(TestablePendingOrderResource::banner($record));
        $this->assertNull(TestablePendingOrderResource::banner($record));
        $this->assertSame($before + 1, TestablePendingOrderResource::$resolutionCount);
    }
}

class TestablePendingOrderResource extends PendingOrderResource
{
    public static int $resolutionCount = 0;

    public static function banner(object $record): ?string
    {
        return parent::sixMonthReviewBannerForPending($record);
    }

    protected static function resolveSixMonthReviewBannerForPending($record): ?string
    {
        static::$resolutionCount++;

        return $record->banner;
    }
}
