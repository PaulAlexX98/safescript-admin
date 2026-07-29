<?php

namespace Tests\Unit;

use App\Filament\Widgets\DailyRevenueTable;
use App\Filament\Widgets\ServicesPerformance;
use App\Support\DatabaseSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DashboardWidgetCachingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseSchema::flush();
        Cache::flush();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('service_slug')->nullable();
            $table->unsignedBigInteger('products_total_minor')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        DB::table('orders')->insert([
            'status' => 'completed',
            'payment_status' => 'paid',
            'service_slug' => 'weight-management',
            'products_total_minor' => 12999,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        DatabaseSchema::flush();

        parent::tearDown();
    }

    public function test_services_report_is_reused_without_requerying_orders(): void
    {
        $widget = new TestableServicesPerformance;

        $first = $widget->records();
        $this->assertNotEmpty($first);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $second = $widget->records();

        $this->assertEquals($first->toArray(), $second->toArray());
        $this->assertSame([], $queries);
    }

    public function test_services_total_is_reused_without_requerying_orders(): void
    {
        $widget = new TestableServicesPerformance;

        $first = $widget->total();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame($first, $widget->total());
        $this->assertSame([], $queries);
    }

    public function test_daily_revenue_is_reused_without_requerying_orders(): void
    {
        $widget = new TestableDailyRevenueTable;

        $first = $widget->records();
        $this->assertNotEmpty($first);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $second = $widget->records();

        $this->assertEquals($first->toArray(), $second->toArray());
        $this->assertSame([], $queries);
    }
}

class TestableServicesPerformance extends ServicesPerformance
{
    public function records(): Collection
    {
        return $this->getTableRecords();
    }

    public function total(): float
    {
        return $this->totalRevenueSum();
    }
}

class TestableDailyRevenueTable extends DailyRevenueTable
{
    public function records(): Collection
    {
        return $this->getTableRecords();
    }
}
