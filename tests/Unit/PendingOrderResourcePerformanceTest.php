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

    public function test_patient_email_uses_order_metadata_and_user_fallbacks(): void
    {
        $metadataRecord = (object) [
            'email' => 'not-an-email',
            'meta' => ['patient' => ['email' => 'patient@example.test']],
            'user' => (object) ['email' => 'user@example.test'],
        ];

        $userRecord = (object) [
            'email' => null,
            'meta' => [],
            'user' => (object) ['email' => 'user@example.test'],
        ];

        $this->assertSame(
            'patient@example.test',
            TestablePendingOrderResource::patientEmail($metadataRecord)
        );
        $this->assertSame(
            'user@example.test',
            TestablePendingOrderResource::patientEmail($userRecord)
        );
    }
}

class TestablePendingOrderResource extends PendingOrderResource
{
    public static int $resolutionCount = 0;

    public static function banner(object $record): ?string
    {
        return parent::sixMonthReviewBannerForPending($record);
    }

    public static function patientEmail(object $record): ?string
    {
        return parent::patientEmailForPending($record);
    }

    protected static function resolveSixMonthReviewBannerForPending($record): ?string
    {
        static::$resolutionCount++;

        return $record->banner;
    }
}
