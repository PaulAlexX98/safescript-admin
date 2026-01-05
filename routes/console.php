<?php

use App\Models\Order;
use App\Models\WmAlert;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('alerts:weight-management-patients {--no-order-days=45} {--registered-days=90}', function () {
    $now = now();

    $noOrderDays = (int) ($this->option('no-order-days') ?? 45);
    if ($noOrderDays < 1) {
        $noOrderDays = 45;
    }

    $registeredDays = (int) ($this->option('registered-days') ?? 90);
    if ($registeredDays < 1) {
        $registeredDays = 90;
    }

    $cutoffNoOrder = $now->copy()->subDays($noOrderDays);
    $cutoffRegistered = $now->copy()->subDays($registeredDays);

    $patientLine = function (Order $order): string {
        $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

        $first = data_get($meta, 'patient.firstName')
            ?? data_get($meta, 'patient.first_name')
            ?? data_get($meta, 'firstName')
            ?? data_get($meta, 'first_name')
            ?? optional($order->user)->first_name
            ?? optional($order->patient)->first_name;

        $last = data_get($meta, 'patient.lastName')
            ?? data_get($meta, 'patient.last_name')
            ?? data_get($meta, 'lastName')
            ?? data_get($meta, 'last_name')
            ?? optional($order->user)->last_name
            ?? optional($order->patient)->last_name;

        $name = trim(((string) ($first ?? '')) . ' ' . ((string) ($last ?? '')));
        if ($name === '') {
            $name = (string) (optional($order->user)->name ?? optional($order->patient)->name ?? 'Patient');
        }

        $phone = data_get($meta, 'patient.phone')
            ?? data_get($meta, 'phone')
            ?? optional($order->patient)->phone
            ?? optional($order->user)->phone
            ?? optional($order->user)->mobile
            ?? '—';

        $email = data_get($meta, 'patient.email')
            ?? data_get($meta, 'email')
            ?? optional($order->patient)->email
            ?? optional($order->user)->email
            ?? '—';

        $phone = is_string($phone) ? trim($phone) : (string) $phone;
        $email = is_string($email) ? trim($email) : (string) $email;

        return $name . ' with contact no ' . ($phone !== '' ? $phone : '—') . ' and email ' . ($email !== '' ? $email : '—');
    };

    $pushAlert = function (string $key, ?int $userId, string $title, string $body, ?string $kind = null) use ($now): void {
        WmAlert::updateOrCreate(
            ['key' => $key],
            [
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'kind' => $kind,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    };

    // Helper: apply weight-management filter regardless of whether service_slug is a real column or stored in orders.meta JSON
    $applyWeightManagementFilter = function ($query) {
        // If a real column exists, still include JSON meta fallback because many records
        // may have service_slug stored only in meta while the column is NULL.
        if (Schema::hasColumn('orders', 'service_slug')) {
            return $query->where(function ($q) {
                $q->where('service_slug', 'weight-management')
                  ->orWhere('meta->service_slug', 'weight-management');
            });
        }

        // JSON column (orders.meta->service_slug)
        return $query->where('meta->service_slug', 'weight-management');
    };

    // (1) Registered X days ago (ONLY PWMN...) — ONE alert per user, based on their FIRST WM PWMN order
    $firstByUserQuery = DB::table('orders')
        ->selectRaw('user_id, MIN(created_at) as first_order_at')
        ->whereNotNull('user_id')
        ->where('reference', 'like', 'PWMN%');

    if (Schema::hasColumn('orders', 'service_slug')) {
        $firstByUserQuery->where(function ($q) {
            $q->where('service_slug', 'weight-management')
              ->orWhere('meta->service_slug', 'weight-management');
        });
    } else {
        $firstByUserQuery->where('meta->service_slug', 'weight-management');
    }

    $firstByUser = $firstByUserQuery
        ->groupBy('user_id')
        ->havingRaw('MIN(created_at) <= ?', [$cutoffRegistered])
        ->limit(1000)
        ->get();

    $registeredKeys = [];
    foreach ($firstByUser as $row) {
        $userId = (int) ($row->user_id ?? 0);
        if (! $userId) {
            continue;
        }

        // Fetch the actual first order record for patientLine() + View button logic
        $firstOrderQuery = Order::query()
            ->where('user_id', $userId)
            ->where('reference', 'like', 'PWMN%')
            ->where('created_at', $row->first_order_at)
            ->orderBy('id', 'asc');

        $firstOrderQuery = $applyWeightManagementFilter($firstOrderQuery);

        $order = $firstOrderQuery->first();
        if (! $order) {
            continue;
        }

        $daysSince = (int) $registeredDays;
        try {
            // Force integer day count (avoid any float output)
            $daysSince = (int) floor(\Carbon\Carbon::parse($order->created_at)->diffInSeconds($now) / 86400);
            if ($daysSince < 0) {
                $daysSince = 0;
            }
        } catch (\Throwable $e) {
            $daysSince = (int) $registeredDays;
        }

        // Display wording: show “3 months” when the *actual* age is >= 90 days (eg 92 days)
        $titleLabel = ($daysSince >= 90) ? '3 months' : ($daysSince . ' days');
        $actualLabel = $daysSince . ' days';

        // ONE alert per user (keeps updating in place, does not spam)
        $key = 'registered_' . $userId;
        $registeredKeys[] = $key;

        $pushAlert(
            $key,
            $userId,
            'Registered ' . $titleLabel . ' ago',
            $patientLine($order) . ' was registered ' . $titleLabel . ' ago (first WM order was ' . $actualLabel . ' ago)',
            'info'
        );
    }

    // (2) No order for 45 days (PWMN or PWMR) based on last WM order per user
    $lastByUserQuery = DB::table('orders')
        ->selectRaw('user_id, MAX(created_at) as last_order_at')
        ->whereNotNull('user_id')
        ->where(function ($q) {
            $q->where('reference', 'like', 'PWMN%')
              ->orWhere('reference', 'like', 'PWMR%');
        });

    if (Schema::hasColumn('orders', 'service_slug')) {
        $lastByUserQuery->where(function ($q) {
            $q->where('service_slug', 'weight-management')
              ->orWhere('meta->service_slug', 'weight-management');
        });
    } else {
        $lastByUserQuery->where('meta->service_slug', 'weight-management');
    }

    $lastByUser = $lastByUserQuery
        ->groupBy('user_id')
        ->havingRaw('MAX(created_at) < ?', [$cutoffNoOrder])
        ->limit(5000)
        ->get();

    $noOrderKeys = [];
    foreach ($lastByUser as $row) {
        $userId = (int) ($row->user_id ?? 0);
        if (! $userId) {
            continue;
        }

        $orderQuery = Order::query()
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('reference', 'like', 'PWMN%')
                  ->orWhere('reference', 'like', 'PWMR%');
            })
            ->orderByDesc('created_at');

        $orderQuery = $applyWeightManagementFilter($orderQuery);

        $order = $orderQuery->first();

        if (! $order) {
            continue;
        }

        $weekKey = $now->format('oW');
        $key = 'no_order_' . $userId . '_' . $weekKey;
        $noOrderKeys[] = $key;

        // Fixed threshold wording for the pill title (use “45 days” when configured)
        $titleLabel = ($noOrderDays === 45) ? '45 days' : ($noOrderDays . ' days');

        // Display actual inactivity days since last WM order
        $daysSinceLast = (int) $noOrderDays;
        try {
            $daysSinceLast = (int) floor(\Carbon\Carbon::parse($order->created_at)->diffInSeconds($now) / 86400);
            if ($daysSinceLast < 0) {
                $daysSinceLast = 0;
            }
        } catch (\Throwable $e) {
            $daysSinceLast = (int) $noOrderDays;
        }

        $actualLabel = $daysSinceLast . ' days';

        $lastRef = (string) ($order->reference ?? $order->id);
        $lastWhen = '';
        try {
            $lastWhen = \Carbon\Carbon::parse($order->created_at)->tz('Europe/London')->format('d M Y');
        } catch (\Throwable $e) {
            $lastWhen = (string) ($order->created_at ?? '');
        }

        $pushAlert(
            $key,
            $userId,
            'No order for ' . $titleLabel,
            $patientLine($order) . ' has not ordered for ' . $titleLabel . '. Last WM order: ' . $lastRef . ' on ' . $lastWhen,
            'warning'
        );
    }

    // Cleanup: remove stale alerts that no longer match current computation
    try {
        // Registered: keep only registered_* keys we computed this run
        if (! empty($registeredKeys)) {
            WmAlert::query()
                ->where('key', 'like', 'registered_%')
                ->whereNotIn('key', $registeredKeys)
                ->delete();
        }

        // No-order: keep only no_order_* keys we computed this run
        if (! empty($noOrderKeys)) {
            WmAlert::query()
                ->where('key', 'like', 'no_order_%')
                ->whereNotIn('key', $noOrderKeys)
                ->delete();
        }
    } catch (\Throwable $e) {
        // ignore cleanup failures
    }

    $this->info('Weight management alerts processed.');
    return 0;
})->purpose('Send admin notifications for weight management inactivity and 3-month registration.');

// Scheduling
// Local testing: run every minute but with real thresholds
if (app()->environment('local')) {
    Schedule::command('alerts:weight-management-patients --no-order-days=45 --registered-days=90')->everyMinute();
} else {
    // Production run daily at 09:00
    Schedule::command('alerts:weight-management-patients')->dailyAt('09:00');
}
