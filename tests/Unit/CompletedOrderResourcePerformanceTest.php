<?php

namespace Tests\Unit;

use App\Filament\Resources\Orders\CompletedOrderResource;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class CompletedOrderResourcePerformanceTest extends TestCase
{
    public function test_listing_uses_an_indexable_completed_at_sort(): void
    {
        $sql = CompletedOrderResource::getEloquentQuery()->toSql();

        $this->assertMatchesRegularExpression(
            '/order by [`"]completed_at[`"] desc, [`"]id[`"] desc/',
            $sql,
        );
        $this->assertStringNotContainsString(
            'COALESCE(completed_at, paid_at, approved_at, created_at)',
            $sql
        );
    }

    public function test_reference_search_uses_an_indexable_prefix_match(): void
    {
        $query = TestableCompletedOrderResource::search('PWMR45659765');
        $sql = $query->toSql();

        $this->assertStringContainsString(
            'reference" like ?',
            str_replace('`', '"', $sql)
        );
        $this->assertSame('PWMR45659765%', $query->getBindings()[1]);
        // One JSON_EXTRACT remains in the base NHS-exclusion predicate. Search
        // itself must not add the former collection of JSON path scans.
        $this->assertSame(1, substr_count($sql, 'JSON_EXTRACT'));
        $this->assertStringNotContainsString('MATCH(orders.meta)', $sql);
    }

    public function test_product_search_uses_one_fulltext_constraint(): void
    {
        $query = TestableCompletedOrderResource::search('Mounjaro 5mg');
        $sql = $query->toSql();

        $this->assertSame(1, substr_count($sql, 'MATCH(meta_orders.meta)'));
        $this->assertStringNotContainsString('JSON_EXTRACT(orders.meta', $sql);
        $this->assertContains('+Mounjaro* +5mg*', $query->getBindings());
    }
}

class TestableCompletedOrderResource extends CompletedOrderResource
{
    public static function search(string $search): Builder
    {
        return parent::applyCompletedOrdersSearch(
            parent::getEloquentQuery(),
            $search
        );
    }
}
