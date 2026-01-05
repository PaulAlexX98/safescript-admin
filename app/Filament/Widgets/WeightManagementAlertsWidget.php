<?php

namespace App\Filament\Widgets;

use App\Models\WmAlert;
use Filament\Widgets\Widget;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use App\Models\Order;
use Illuminate\Support\Facades\URL;

class WeightManagementAlertsWidget extends Widget
{
    protected string $view = 'filament.widgets.weight-management-alerts-widget';

    protected int | string | array $columnSpan = 'full';

    public function getAlerts()
    {
        // Preferred: read alerts from wm_alerts table (reliable across CLI and web)
        if (Schema::hasTable('wm_alerts')) {
            return WmAlert::query()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($a) {
                    $userId = (int) ($a->user_id ?? 0);

                    $order = null;

                    try {
                        // Weight management filter: column or JSON
                        $applyWeight = function ($q) {
                            if (Schema::hasColumn('orders', 'service_slug')) {
                                return $q->where('service_slug', 'weight-management');
                            }
                            return $q->where('meta->service_slug', 'weight-management');
                        };

                        if ($userId) {
                            $q = Order::query()->where('user_id', $userId);
                            $q = $applyWeight($q);

                            if (($a->title ?? null) === 'Registered 3 months ago') {
                                // First ever weight management order for this user (PWMN)
                                $q->where('reference', 'like', 'PWMN%')
                                  ->orderBy('created_at', 'asc');
                            } else {
                                // Latest WM order for this user (PWMN or PWMR)
                                $q->where(function ($qq) {
                                        $qq->where('reference', 'like', 'PWMN%')
                                           ->orWhere('reference', 'like', 'PWMR%');
                                    })
                                  ->orderBy('created_at', 'desc');
                            }

                            $order = $q->first();
                        }

                        // Fallback: if alert key contains a reference (registered_3m_PWMNxxxx)
                        if (! $order && is_string($a->key ?? null) && str_starts_with($a->key, 'registered_3m_')) {
                            $ref = (string) str_replace('registered_3m_', '', $a->key);
                            if ($ref !== '') {
                                $q = Order::query()->where('reference', $ref);
                                $q = $applyWeight($q);
                                $order = $q->first();
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    $orderUrl = $order
                        ? URL::to('/admin/orders/completed-orders/' . $order->id . '/details')
                        : null;

                    return [
                        'id' => $a->id,
                        'key' => $a->key,
                        'user_id' => $userId ?: null,
                        'order_id' => $order?->id,
                        'order_url' => $orderUrl,
                        'title' => $a->title,
                        'body' => $a->body,
                        'kind' => $a->kind,
                        'created_at' => $a->created_at,
                    ];
                });
        }

        // If database notifications table exists, read the latest matching notifications
        if (Schema::hasTable('notifications')) {
            $user = Auth::user();

            if (! $user) {
                return collect();
            }

            return DatabaseNotification::query()
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->getKey())
                ->whereIn('data->title', [
                    'No order for 45 days',
                    'Registered 3 months ago',
                ])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(function ($n) {
                    return [
                        'title' => data_get($n->data, 'title', 'Alert'),
                        'body' => data_get($n->data, 'body', ''),
                        'created_at' => $n->created_at,
                    ];
                });
        }

        // Fallback: read alerts from cache (global for all admins)
        $rows = Cache::get('wm_alerts_latest', []);

        return collect(is_array($rows) ? $rows : [])
            ->take(10)
            ->map(function ($row) {
                $ts = data_get($row, 'created_at');
                return [
                    'title' => (string) data_get($row, 'title', 'Alert'),
                    'body' => (string) data_get($row, 'body', ''),
                    'created_at' => $ts ? Carbon::parse($ts) : now(),
                ];
            });
    }
    public function addTestAlert(): void
    {
        $now = now();

        if (Schema::hasTable('wm_alerts')) {
            WmAlert::create([
                'key' => 'test_' . $now->format('YmdHis') . '_' . uniqid(),
                'user_id' => Auth::id(),
                'title' => 'No order for 45 days',
                'body' => 'Test patient with contact no 07000 000000 and email test@example.com has not ordered for 45 days',
                'kind' => 'warning',
                'meta' => null,
            ]);

            $this->dispatch('wm-alerts-updated');
            return;
        }

        $existing = Cache::get('wm_alerts_latest', []);
        $existing = is_array($existing) ? $existing : [];

        array_unshift($existing, [
            'title' => 'Test alert',
            'body' => 'Test patient with contact no 07000 000000 and email test@example.com has not ordered for 45 days',
            'created_at' => $now->toDateTimeString(),
        ]);

        $existing = array_slice($existing, 0, 50);

        Cache::put('wm_alerts_latest', $existing, $now->copy()->addDays(365));

        $this->dispatch('wm-alerts-updated');
    }

    public function clearAlerts(): void
    {
        if (Schema::hasTable('wm_alerts')) {
            WmAlert::query()->delete();
            $this->dispatch('wm-alerts-updated');
            return;
        }

        Cache::forget('wm_alerts_latest');
        $this->dispatch('wm-alerts-updated');
    }

    public function dismissAlert(int $id): void
    {
        if (Schema::hasTable('wm_alerts')) {
            WmAlert::query()->whereKey($id)->delete();
            $this->dispatch('wm-alerts-updated');
            return;
        }
    }
}