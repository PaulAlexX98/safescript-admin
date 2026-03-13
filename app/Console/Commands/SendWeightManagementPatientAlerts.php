<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Models\WmAlert;
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

            $lastOrder = $this->weightManagementOrders()
                ->where('user_id', $u->id)
                ->orderByDesc('created_at')
                ->first();

            $msg = $this->patientLineFromOrder($lastOrder, $u) . ' has not ordered for 45 days';

            if ($lastOrder) {
                $msg .= '. Last WM order: ' . ($lastOrder->reference ?: '—') . ' on ' . optional($lastOrder->created_at)->format('d M Y');
            }

            Notification::make()
                ->title('No order for 45 days')
                ->body($msg)
                ->warning()
                ->sendToDatabase($admins);

            WmAlert::firstOrCreate(
                ['key' => $key],
                [
                    'user_id' => $u->id,
                    'title' => 'No order for 45 days',
                    'body' => $msg,
                    'kind' => 'warning',
                    'meta' => null,
                ]
            );

            // Dedupe so it does not spam daily (adjust if you want weekly etc)
            Cache::put($key, true, $now->copy()->addDays(7));
        }

        // 2) Every 6 months since the FIRST weight-management order
        $firstWmOrders = $this->weightManagementOrders()
            ->selectRaw('user_id, MIN(created_at) as first_order_at')
            ->whereNotNull('user_id')
            ->groupBy('user_id');

        $reviewUsers = User::query()
            ->joinSub($firstWmOrders, 'wm_first', function ($join) {
                $join->on('users.id', '=', 'wm_first.user_id');
            })
            ->select('users.*', 'wm_first.first_order_at')
            ->get();

        foreach ($reviewUsers as $u) {
            $firstOrderAt = $u->first_order_at ? Carbon::parse($u->first_order_at) : null;
            if (! $firstOrderAt) {
                continue;
            }

            $daysSince = (int) $firstOrderAt->diffInDays($now);
            $cycle = (int) floor($daysSince / 180);

            if ($cycle < 1) {
                continue;
            }

            $monthsDue = $cycle * 6;
            $key = 'wm_alert_review_' . $u->id . '_' . $monthsDue . 'm';
            if (Cache::has($key)) {
                continue;
            }

            $firstOrder = $this->weightManagementOrders()
                ->where('user_id', $u->id)
                ->orderBy('created_at')
                ->first();

            $msg = $this->patientLineFromOrder($firstOrder, $u)
                . ' is due for their '
                . $monthsDue
                . '-month weight management review';

            if ($firstOrder) {
                $msg .= '. First WM order: ' . ($firstOrder->reference ?: '—') . ' on ' . optional($firstOrder->created_at)->format('d M Y');
            }

            Notification::make()
                ->title('Weight management review due')
                ->body($msg)
                ->info()
                ->sendToDatabase($admins);

            WmAlert::firstOrCreate(
                ['key' => $key],
                [
                    'user_id' => $u->id,
                    'title' => 'Weight management review due',
                    'body' => $msg,
                    'kind' => 'info',
                    'meta' => [
                        'months_due' => $monthsDue,
                        'first_order_at' => $firstOrderAt->toDateTimeString(),
                    ],
                ]
            );

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
              ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.service')) = ?", [$slug])
              ->orWhere('reference', 'like', 'PWMN%')
              ->orWhere('reference', 'like', 'PWMR%');
        });
    }

    private function patientLineFromOrder(?Order $order, User $u): string
    {
        $meta = is_array($order?->meta) ? $order->meta : (json_decode($order?->meta ?? '[]', true) ?: []);

        $name = trim(
            (string) ($meta['firstName'] ?? $meta['first_name'] ?? $meta['name'] ?? $meta['full_name'] ?? '')
        );

        if ($name === '') {
            $first = (string) ($u->first_name ?? '');
            $last = (string) ($u->last_name ?? '');
            $name = trim($first . ' ' . $last);
        }

        if ($name === '') {
            $name = is_string($u->name ?? null) && trim($u->name) !== '' ? trim($u->name) : 'Patient';
        }

        $phone = $meta['phone']
            ?? $meta['mobile']
            ?? data_get($meta, 'patient.phone')
            ?? $u->phone
            ?? $u->mobile
            ?? $u->phone_number
            ?? $u->contact_no
            ?? '—';

        $email = $meta['email']
            ?? data_get($meta, 'patient.email')
            ?? (is_string($u->email ?? null) && trim($u->email) !== '' ? trim($u->email) : '—');

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