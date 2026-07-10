<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
  

    /**
     * Bootstrap any application services.
     */

    public function boot(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if ($query->time >= 200) {
                Log::warning('Slow SQL query', [
                    'time_ms' => $query->time,
                    'sql' => $query->toRawSql(),
                ]);
            }
        });

        View::composer([
            'consultations.*',  // your risk-assessment, reorder, etc.
            'pdf.*',            // your record-of-supply / declaration PDFs
        ], function ($view) {
            $u = auth()->user();

            $view->with('pharmacistFromProfile', [
                'name'      => $u?->pharmacist_display_name ?: $u?->name,
                'gphc'      => $u?->gphc_number,
                'signature' => $u?->signature_url, // accessor on User model
            ]);
        });
    }
}
