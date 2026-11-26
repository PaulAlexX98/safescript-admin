<?php

namespace Tests\Unit;

use App\Filament\Resources\ApprovedOrders\ApprovedOrderResource;
use Tests\TestCase;

class ApprovedOrderAppointmentColumnTest extends TestCase
{
    public function test_appointment_column_formats_the_appointment_from_order_meta(): void
    {
        $record = (object) [
            'meta' => ['appointment_start_at' => '2026-07-10T16:30:00Z'],
            'id' => null,
            'reference' => null,
        ];

        $this->assertSame(
            '10 Jul 2026, 17:30',
            TestableApprovedOrderResource::appointmentDateTime($record),
        );
    }

    public function test_appointment_column_supports_separate_date_and_time_meta(): void
    {
        $record = (object) [
            'meta' => [
                'appointment_date' => '2026-11-26',
                'appointment_time' => '14:15',
            ],
            'id' => null,
            'reference' => null,
        ];

        $this->assertSame(
            '26 Nov 2026, 14:15',
            TestableApprovedOrderResource::appointmentDateTime($record),
        );
    }
}

class TestableApprovedOrderResource extends ApprovedOrderResource
{
    public static function appointmentDateTime($record): ?string
    {
        return parent::appointmentDateTimeForApproved($record);
    }
}
