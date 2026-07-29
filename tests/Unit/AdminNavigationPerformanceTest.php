<?php

namespace Tests\Unit;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNavigationPerformanceTest extends TestCase
{
    public function test_building_global_admin_navigation_executes_no_database_queries(): void
    {
        config(['cache.default' => 'array']);

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
