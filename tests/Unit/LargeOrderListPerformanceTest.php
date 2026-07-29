<?php

namespace Tests\Unit;

use App\Filament\Resources\NhsCompleteds\NhsCompletedResource;
use App\Filament\Resources\NhsRejecteds\NhsRejectedResource;
use App\Filament\Resources\PendingOrders\PendingOrderResource;
use App\Filament\Resources\UnpaidOrders\UnpaidOrderResource;
use App\Support\DatabaseSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LargeOrderListPerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseSchema::flush();

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->nullable();
            $table->string('payment_status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('decision_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DatabaseSchema::flush();

        parent::tearDown();
    }

    public function test_pending_and_unpaid_use_scalar_payment_status_first(): void
    {
        $pendingSql = str_replace('`', '"', PendingOrderResource::getEloquentQuery()->toSql());
        $unpaidSql = str_replace('`', '"', UnpaidOrderResource::getEloquentQuery()->toSql());

        $this->assertStringContainsString('"payment_status" = ?', $pendingSql);
        $this->assertStringContainsString('"payment_status" = ?', $unpaidSql);
        $this->assertStringNotContainsString('LOWER(payment_status)', $pendingSql);
        $this->assertStringNotContainsString('LOWER(payment_status)', $unpaidSql);
        $this->assertStringContainsString('not exists', strtolower($unpaidSql));
    }

    public function test_nhs_archive_queries_use_the_real_orders_status_column(): void
    {
        $completedSql = str_replace('`', '"', NhsCompletedResource::getEloquentQuery()->toSql());
        $rejectedSql = str_replace('`', '"', NhsRejectedResource::getEloquentQuery()->toSql());

        $this->assertStringContainsString('"status" = ?', $completedSql);
        $this->assertStringContainsString('"status" = ?', $rejectedSql);
        $this->assertStringNotContainsString('nhs_pendings', $completedSql);
        $this->assertStringNotContainsString('nhs_pendings', $rejectedSql);
        $this->assertStringContainsString('"reference" like ?', $completedSql);
        $this->assertStringContainsString('"reference" like ?', $rejectedSql);
    }
}
