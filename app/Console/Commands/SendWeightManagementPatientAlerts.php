<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SendWeightManagementPatientAlerts extends Command
{
    protected $signature = 'alerts:weight-management-patients';
    protected $description = 'Send admin notifications for weight-management patients';

    public function handle(): int
    {
        $now = now();
        $cutoff45 = $now->copy()->subDays(45);
        $cutoff3m = $now->copy()->subMonths(3);

        $admins = $this->resolveAdmins();
        if ($admins->isEmpty()) {
            return self::SUCCESS;
        }

        // Subquery: last order date for weight-management orders per user
        $lastWmOrders = $this->weightManagementOrders()
            ->selectRaw('user_id, MAX(created_at) as last_order_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        // 1) No order for 45 days (only weight-management patients)
        $inactive = User::query()
            ->joinSub($lastWmOrders, 'wm', function ($join) {
                $join->on('users.id', '=', 'wm.user_id');
            })
            ->where('wm.last_order_at', '<', $cutoff45)
            ->select('users.*', 'wm.last_order_at')
            ->get();

        foreach ($inactive as $u) {
            $key = 'wm_alert_no_order_45_' . $u->id;
            if (Cache::has($key)) {
                continue;
            }

            $msg = $this->patientLine($u) . ' has not ordered for 45 days';

            Notification::make()
                ->title('No order for 45 days')
                ->body($msg)
                ->warning()
                ->sendToDatabase($admins);

            // Dedupe so it does not spam daily (adjust if you want weekly etc)
            Cache::put($key, true, $now->copy()->addDays(7));
        }

        // 2) Registered 3 months ago (only weight-management patients)
        $registered = User::query()
            ->joinSub($lastWmOrders, 'wm', function ($join) {
                $join->on('users.id', '=', 'wm.user_id');
            })
            ->where('users.created_at', '<=', $cutoff3m)
            ->select('users.*')
            ->get();

        foreach ($registered as $u) {
            $key = 'wm_alert_registered_3m_' . $u->id;
            if (Cache::has($key)) {
                continue;
            }

            $msg = $this->patientLine($u) . ' was registered 3 months ago';

            Notification::make()
                ->title('Registered 3 months ago')
                ->body($msg)
                ->info()
                ->sendToDatabase($admins);

            Cache::put($key, true, $now->copy()->addDays(30));
        }

        return self::SUCCESS;
    }

    private function weightManagementOrders(): Builder
    {
        $slug = 'weight-management';

        $q = Order::query();

        // Prefer a real column if it exists
        if (Schema::hasColumn('orders', 'service_slug')) {
            return $q->where('service_slug', $slug);
        }

        // Otherwise try JSON meta shapes commonly used in this project
        return $q->where(function (Builder $w) use ($slug) {
            $w->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service_slug')) = ?", [$slug])
              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service.slug')) = ?", [$slug])
              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service')) = ?", [$slug]);
        });
    }

    private function patientLine(User $u): string
    {
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        if ($name === '') {
            $name = is_string($u->name ?? null) && trim($u->name) !== '' ? trim($u->name) : 'Patient';
        }

        $phone =
            $u->phone
            ?? $u->mobile
            ?? $u->phone_number
            ?? $u->contact_no
            ?? '—';

        $email = is_string($u->email ?? null) && trim($u->email) !== '' ? trim($u->email) : '—';

        return "{$name} with contact no {$phone} and email {$email}";
    }

    private function resolveAdmins()
    {
        // Adjust this to match how you identify admins
        // Option A: is_admin boolean
        if (Schema::hasColumn('users', 'is_admin')) {
            return User::query()->where('is_admin', true)->get();
        }

        // Option B: role column
        if (Schema::hasColumn('users', 'role')) {
            return User::query()->whereIn('role', ['admin', 'superadmin'])->get();
        }

        // Fallback: send to all users (not ideal)
        return User::query()->limit(1)->get();
    }
}