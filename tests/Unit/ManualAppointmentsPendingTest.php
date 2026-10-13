<?php

namespace Tests\Unit;

use App\Filament\Resources\PendingOrders\Widgets\ManualAppointmentsPending;
use Tests\TestCase;

class ManualAppointmentsPendingTest extends TestCase
{
    public function test_query_only_targets_active_manual_appointments(): void
    {
        $query = ManualAppointmentsPending::query();
        $sql = strtolower($query->toSql());
        $normalizedSql = str_replace(['`', '"'], '', $sql);
        $bindings = $query->getBindings();

        $this->assertStringContainsString('order_id is null', $normalizedSql);
        $this->assertStringContainsString('order_reference like ?', $normalizedSql);
        $this->assertStringContainsString('start_at is not null', $normalizedSql);
        $this->assertStringContainsString('start_at >= ?', $normalizedSql);
        $this->assertStringContainsString('appointments.deleted_at is null', $normalizedSql);
        $this->assertStringContainsString('order by start_at asc', $normalizedSql);

        $this->assertContains('PCAO%', $bindings);
        $this->assertContains('completed', $bindings);
        $this->assertContains('cancelled', $bindings);
        $this->assertContains('rejected', $bindings);
    }
}
