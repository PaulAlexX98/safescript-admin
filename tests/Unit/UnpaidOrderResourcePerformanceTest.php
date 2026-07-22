<?php

namespace Tests\Unit;

use App\Filament\Resources\UnpaidOrders\UnpaidOrderResource;
use App\Models\Order;
use PHPUnit\Framework\TestCase;

class UnpaidOrderResourcePerformanceTest extends TestCase
{
    public function test_latest_order_is_resolved_once_per_modal_record(): void
    {
        $record = (object) ['reference' => 'TEST-UNPAID-1'];

        $first = TestableUnpaidOrderResource::latestOrder($record);
        $second = TestableUnpaidOrderResource::latestOrder($record);

        $this->assertSame($first, $second);
        $this->assertSame('TEST-UNPAID-1', $first?->reference);
        $this->assertSame(1, TestableUnpaidOrderResource::$resolutionCount);
    }
}

class TestableUnpaidOrderResource extends UnpaidOrderResource
{
    public static int $resolutionCount = 0;

    public static function latestOrder(object $record): ?Order
    {
        return parent::latestOrderForRecord($record);
    }

    protected static function resolveLatestOrderForRecord($record): ?Order
    {
        static::$resolutionCount++;

        return new Order(['reference' => $record->reference]);
    }
}
