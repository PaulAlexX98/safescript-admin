<?php

namespace App\Services\Shipping;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** The single authority for the Royal Mail despatch-email queue. */
class DespatchEmailQueue
{
    public function eligible(): array
    {
        $recipients = [];
        $clickAndDrop = app(ClickAndDrop::class);

        Order::query()->with(['patient', 'user'])->where('status', 'completed')
            ->orderByDesc('completed_at')->orderByDesc('id')->chunkById(100, function ($orders) use (&$recipients, $clickAndDrop): void {
                foreach ($orders as $order) {
                    $candidate = $this->candidate($order, $clickAndDrop);
                    if ($candidate !== null) {
                        $recipients[] = $candidate;
                    }
                }
            });

        return $recipients;
    }

    public function send(array $orderIds): array
    {
        $result = ['requested' => count($orderIds), 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $clickAndDrop = app(ClickAndDrop::class);

        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $id) {
            $order = Order::query()->with(['patient', 'user'])->find($id);
            $candidate = $order ? $this->candidate($order, $clickAndDrop) : null;
            if ($candidate === null || ! $this->claim($order)) {
                $result['skipped']++;
                continue;
            }

            try {
                OrderResource::sendRoyalMailDespatchEmail($order->fresh(['patient', 'user']), $candidate['tracking_number'], $candidate['click_and_drop_order']);
                $result['sent']++;
            } catch (\Throwable $e) {
                $this->release($order, $e->getMessage());
                $result['failed']++;
                Log::warning('royalmail.despatch_email.failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    public function skip(array $orderIds): array
    {
        $skipped = 0;
        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $id) {
            $order = Order::query()->find($id);
            if (! $order || strtolower((string) $order->status) !== 'completed') continue;
            $meta = $this->meta($order);
            // Do not overwrite the in-progress claim: that would allow a
            // concurrently executing send to deliver an email after a skip.
            if (data_get($meta, 'shipping.dispatch_email_sent_at') || data_get($meta, 'shipping.dispatch_email_skipped_at') || data_get($meta, 'shipping.dispatch_email_sending_at')) continue;
            data_set($meta, 'shipping.dispatch_email_skipped_at', now()->toIso8601String());
            data_set($meta, 'shipping.dispatch_email_skipped_by', auth()->id());
            data_set($meta, 'shipping.dispatch_email_sending_at', null);
            $order->meta = $meta;
            $order->save();
            $skipped++;
        }
        return ['requested' => count($orderIds), 'skipped' => $skipped];
    }

    private function candidate(Order $order, ClickAndDrop $clickAndDrop): ?array
    {
        $meta = $this->meta($order);
        if (data_get($meta, 'shipping.dispatch_email_sent_at') || data_get($meta, 'shipping.dispatch_email_skipped_at') || data_get($meta, 'shipping.dispatch_email_sending_at')) return null;
        $identifier = data_get($meta, 'clickanddrop.created_order_identifier') ?? data_get($meta, 'clickanddrop.order_identifier') ?? data_get($meta, 'clickanddrop.created_order.orderIdentifier') ?? data_get($meta, 'shipping.response.createdOrders.0.orderIdentifier');
        if (! $identifier) return null;
        $clickAndDropOrder = $clickAndDrop->getOrder($identifier);
        $tracking = data_get($clickAndDropOrder, 'trackingNumber') ?? data_get($clickAndDropOrder, 'packages.0.trackingNumber') ?? data_get($meta, 'shipping.tracking_number') ?? data_get($meta, 'clickanddrop.tracking_number');
        $email = data_get($meta, 'email') ?? data_get($meta, 'patient.email') ?? optional($order->patient)->email ?? optional($order->user)->email;
        if (! $tracking || ! $email || (! filled(data_get($clickAndDropOrder, 'manifestedOn')) && ! filled(data_get($clickAndDropOrder, 'shippedOn')))) return null;
        $first = data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? data_get($meta, 'patient.firstName') ?? optional($order->patient)->first_name ?? optional($order->user)->first_name ?? '';
        $last = data_get($meta, 'lastName') ?? data_get($meta, 'last_name') ?? data_get($meta, 'patient.lastName') ?? optional($order->patient)->last_name ?? optional($order->user)->last_name ?? '';
        return ['id' => $order->id, 'name' => trim($first.' '.$last) ?: 'Patient', 'reference' => $order->reference, 'email' => $email, 'tracking_number' => (string) $tracking, 'click_and_drop_order' => $clickAndDropOrder];
    }

    private function claim(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->find($order->id);
            if (! $locked || strtolower((string) $locked->status) !== 'completed') return false;
            $meta = $this->meta($locked);
            if (data_get($meta, 'shipping.dispatch_email_sent_at') || data_get($meta, 'shipping.dispatch_email_skipped_at') || data_get($meta, 'shipping.dispatch_email_sending_at')) return false;
            data_set($meta, 'shipping.dispatch_email_sending_at', now()->toIso8601String());
            data_set($meta, 'shipping.dispatch_email_sending_by', auth()->id());
            $locked->meta = $meta;
            $locked->save();
            return true;
        });
    }

    private function release(Order $order, string $error): void
    {
        $order->refresh(); $meta = $this->meta($order);
        data_set($meta, 'shipping.dispatch_email_sending_at', null); data_set($meta, 'shipping.dispatch_email_last_error', $error);
        $order->meta = $meta; $order->save();
    }

    private function meta(Order $order): array { return is_array($order->meta) ? $order->meta : (json_decode($order->meta ?: '[]', true) ?: []); }
}
