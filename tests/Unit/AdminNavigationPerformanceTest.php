<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\AdminNavigationCounts;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNavigationPerformanceTest extends TestCase
{
    public function test_building_global_admin_navigation_executes_no_database_queries(): void
    {
        config(['cache.default' => 'array']);
        AdminNavigationCounts::flushRequestCache();
        Cache::putMany([
            AdminNavigationCounts::CACHE_KEY => [
                'pending' => 7,
                'approved' => 4,
                'completed' => 123,
            ],
            'illuminate:cache:flexible:created:'.AdminNavigationCounts::CACHE_KEY => now()->getTimestamp(),
        ], 3600);

        $user = new User([
            'name' => 'Performance Test Admin',
            'email' => 'performance@example.test',
        ]);
        $user->id = 1;
        $user->exists = true;

        Auth::setUser($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $navigation = Filament::getNavigation();

        $this->assertNotEmpty($navigation);
        $this->assertSame([], $queries, implode("\n", $queries));
    }
}
