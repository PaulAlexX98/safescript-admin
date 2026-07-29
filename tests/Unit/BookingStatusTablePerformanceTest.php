<?php

namespace Tests\Unit;

use App\Filament\Widgets\BookingStatusTable;
use App\Support\DatabaseSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingStatusTablePerformanceTest extends TestCase
{
    protected function tearDown(): void
    {
        DatabaseSchema::flush();

        parent::tearDown();
    }

    public function test_status_counts_and_revenue_use_indexable_filtered_queries(): void
    {
        DatabaseSchema::flush();

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->string('status')->nullable();
                $table->string('booking_status')->nullable();
                $table->string('payment_status')->nullable();
                $table->unsignedBigInteger('products_total_minor')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->string('service_slug')->nullable();
            });
        }

        $now = now();
        DB::table('orders')->insert([
            [
                'status' => 'completed',
                'booking_status' => 'approved',
                'payment_status' => 'paid',
                'products_total_minor' => 1000,
                'completed_at' => $now,
                'paid_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
                'service_slug' => 'weight-management',
            ],
            [
                'status' => 'rejected',
                'booking_status' => 'rejected',
                'payment_status' => 'paid',
                'products_total_minor' => 2000,
                'completed_at' => null,
                'paid_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
                'service_slug' => 'travel',
            ],
            [
                'status' => 'pending',
                'booking_status' => 'pending',
                'payment_status' => 'unpaid',
                'products_total_minor' => 3000,
                'completed_at' => null,
                'paid_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
                'service_slug' => 'weight-management',
            ],
        ]);

        $widget = new TestableBookingStatusTable;

        // Warm the process-local schema capability cache. Those information_schema
        // checks are shared by all dashboard widgets and are not data aggregates.
        $widget->statusMetrics();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $metrics = $widget->statusMetrics();

        $this->assertCount(3, $queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString(' where ', strtolower($sql));
            $this->assertStringNotContainsString('case when', strtolower($sql));
        }
        $this->assertStringContainsString('orders.status = ?', $queries[0]);
        $this->assertStringContainsString('orders.booking_status = ?', $queries[1]);
        $this->assertStringContainsString('orders.payment_status = ?', $queries[2]);
        $this->assertSame(['completed', 'rejected', 'unpaid'], array_keys($metrics));
        $this->assertSame(['count' => 1, 'revenue' => 10.0], $metrics['completed']);
        $this->assertSame(['count' => 1, 'revenue' => 20.0], $metrics['rejected']);
        $this->assertSame(['count' => 1, 'revenue' => 30.0], $metrics['unpaid']);
    }
}

class TestableBookingStatusTable extends BookingStatusTable
{
    /**
     * @return array<string, array{count: int, revenue: float}>
     */
    public function statusMetrics(): array
    {
        return $this->loadStatusMetrics();
    }
}
