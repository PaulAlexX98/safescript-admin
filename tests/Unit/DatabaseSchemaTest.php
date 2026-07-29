<?php

namespace Tests\Unit;

use App\Support\DatabaseSchema;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseSchema::flush();
    }

    protected function tearDown(): void
    {
        DatabaseSchema::flush();

        parent::tearDown();
    }

    public function test_column_listing_is_loaded_only_once_per_table(): void
    {
        Schema::shouldReceive('getColumnListing')
            ->once()
            ->with('orders')
            ->andReturn(['id', 'status', 'paid_at']);

        $this->assertTrue(DatabaseSchema::hasColumn('orders', 'status'));
        $this->assertTrue(DatabaseSchema::hasColumn('orders', 'paid_at'));
        $this->assertFalse(DatabaseSchema::hasColumn('orders', 'missing'));
    }
}
