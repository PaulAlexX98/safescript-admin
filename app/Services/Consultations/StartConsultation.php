<?php

namespace App\Services\Consultations;

use App\Models\ApprovedOrder;
use App\Models\ClinicForm;
use App\Models\ConsultationSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class StartConsultation
{
    public function __invoke(ApprovedOrder $order): ConsultationSession
    {
        // If a session already exists for this order, reuse it
        if ($existing = ConsultationSession::where('order_id', $order->id)->first()) {
            return $existing;
        }

        // Resolve service + treatment from the order (be generous with sources)
        $service = $this->firstSlug([
            $order->service_slug ?? null,
            $order->service ?? null,
            $order->service_name ?? null,
            Arr::get($order->meta ?? [], 'service.slug'),
            Arr::get($order->meta ?? [], 'service'),
            'weight-management-service',
        ]);

        $treat = $this->firstSlug([
            $order->treatment_slug ?? null,
            $order->treatment ?? null,
            $order->product_slug ?? null,
            $order->product ?? null,
            // common meta shapes from your orders
            Arr::get($order->meta ?? [], 'treatment.slug'),
            Arr::get($order->meta ?? [], 'treatment'),
            Arr::get($order->meta ?? [], 'product.slug'),
            Arr::get($order->meta ?? [], 'product'),
            Arr::get($order->meta ?? [], 'selectedProduct.slug'),
            Arr::get($order->meta ?? [], 'selectedProduct.name'),
            Arr::get($order->meta ?? [], 'items.0.product.slug'),
            Arr::get($order->meta ?? [], 'items.0.product.name'),
            Arr::get($order->meta ?? [], 'items.0.slug'),
            Arr::get($order->meta ?? [], 'items.0.name'),
            Arr::get($order->meta ?? [], 'lines.0.slug'),
            Arr::get($order->meta ?? [], 'lines.0.name'),
            Arr::get($order->meta ?? [], 'line_items.0.slug'),
            Arr::get($order->meta ?? [], 'line_items.0.name'),
        ]);

        // We’re strict: a treatment must be present.
        if (! $treat) {
            throw new \RuntimeException('Missing treatment for this service; cannot start consultation.');
        }

        // Helper to fetch a specific form_type for the exact service+treatment
        $pick = function (string $type) use ($service, $treat) {
            return ClinicForm::query()
                ->where('is_active', true)
                ->where('form_type', $type)
                ->where('service_slug', $service)
                ->where('treatment_slug', $treat)
                ->orderByDesc('version')
                ->first();
        };

        // Allow missing RAF for now; require at least one available template
        $orderedKeys = ['raf', 'advice', 'declaration', 'supply'];
        $templates = [
            'raf'         => $pick('raf'),
            'advice'      => $pick('advice'),
            'declaration' => $pick('declaration'),
            'supply'      => $pick('supply'),
        ];

        $available = array_filter($templates, fn ($t) => (bool) $t);
        if (empty($available)) {
            throw new \RuntimeException("No consultation templates found for service='{$service}' treatment='" . ($treat ?: 'generic') . "'.");
        }

        $stepKeys = array_values(array_intersect($orderedKeys, array_keys($available)));

        // Snapshot the forms we’ll use (only available ones)
        $snapshot = [];
        foreach ($stepKeys as $key) {
            $form = $available[$key];
            $snapshot[$key] = [
                'id'      => $form->id,
                'name'    => $form->name,
                'version' => $form->version,
                'schema'  => $form->schema,
            ];
        }

        // Create the session
        $session = ConsultationSession::create([
            'order_id'  => $order->id,
            'service'   => $service,
            'treatment' => $treat,
            'templates' => $snapshot,   // json column on the session
            'steps'     => $stepKeys,
            'current'   => 0,
        ]);

        // Denormalise essential info back onto the order->meta so downstream UIs can rely on it
        $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

        // Ensure we can link back to the session later (used by Completed Order details & PDFs)
        $meta['consultation_session_id'] = $session->id;

        // Snapshot patient fields (fallback to user record if not in meta)
        $meta['firstName'] = $meta['firstName'] ?? ($order->user->first_name ?? (\Illuminate\Support\Arr::get($order->meta ?? [], 'patient.firstName') ?? null));
        $meta['lastName']  = $meta['lastName']  ?? ($order->user->last_name  ?? (\Illuminate\Support\Arr::get($order->meta ?? [], 'patient.lastName')  ?? null));
        $meta['email']     = $meta['email']     ?? ($order->user->email      ?? (\Illuminate\Support\Arr::get($order->meta ?? [], 'patient.email')     ?? null));
        $meta['phone']     = $meta['phone']     ?? ($order->user->phone      ?? (\Illuminate\Support\Arr::get($order->meta ?? [], 'patient.phone')     ?? null));
        $meta['dob']       = $meta['dob']       ?? (\Illuminate\Support\Arr::get($order->meta ?? [], 'dateOfBirth')
                                                    ?? \Illuminate\Support\Arr::get($order->meta ?? [], 'patient.dob')
                                                    ?? ($order->user->dob ?? null));

        // Snapshot payment status from the column so the UI doesn't have to guess where to read it
        if (!isset($meta['payment_status']) || $meta['payment_status'] === null || $meta['payment_status'] === '') {
            $meta['payment_status'] = (string) ($order->payment_status ?? '');
        }

        // Normalise items so each item has a `variation` string even if only `variations/optionLabel/dose/strength` were set
        $items = \Illuminate\Support\Arr::get($meta, 'items')
              ?? \Illuminate\Support\Arr::get($meta, 'line_items')
              ?? \Illuminate\Support\Arr::get($meta, 'lines')
              ?? \Illuminate\Support\Arr::get($meta, 'cart.items');

        if (empty($items)) {
            $sp = \Illuminate\Support\Arr::get($meta, 'selectedProduct') ?? [];
            if (!empty($sp)) {
                $items = [[
                    'name'       => $sp['name'] ?? (\Illuminate\Support\Arr::get($sp, 'title', 'Item')),
                    'qty'        => (int) ($sp['qty'] ?? 1),
                    'variation'  => (string) (\Illuminate\Support\Arr::get($sp, 'variation')
                                        ?? \Illuminate\Support\Arr::get($sp, 'variations')
                                        ?? \Illuminate\Support\Arr::get($sp, 'optionLabel')
                                        ?? \Illuminate\Support\Arr::get($sp, 'variant')
                                        ?? \Illuminate\Support\Arr::get($sp, 'dose')
                                        ?? \Illuminate\Support\Arr::get($sp, 'strength')
                                        ?? ''),
                    'unitMinor'  => \Illuminate\Support\Arr::get($sp, 'unitMinor'),
                    'totalMinor' => \Illuminate\Support\Arr::get($sp, 'totalMinor'),
                ]];
            }
        }

        if (is_array($items)) {
            foreach ($items as &$it) {
                if (empty($it['variation'])) {
                    $it['variation'] = (string) (\Illuminate\Support\Arr::get($it, 'variation')
                                        ?? \Illuminate\Support\Arr::get($it, 'variations')
                                        ?? \Illuminate\Support\Arr::get($it, 'optionLabel')
                                        ?? \Illuminate\Support\Arr::get($it, 'variant')
                                        ?? \Illuminate\Support\Arr::get($it, 'dose')
                                        ?? \Illuminate\Support\Arr::get($it, 'strength')
                                        ?? '');
                }
                // keep qty sane
                if (!isset($it['qty'])) {
                    $it['qty'] = (int) (\Illuminate\Support\Arr::get($it, 'quantity', 1));
                }
                if ($it['qty'] < 1) { $it['qty'] = 1; }
            }
            unset($it);
            $meta['items'] = array_values($items);
        }

        $order->meta = $meta;
        $order->save();

        return $session;
    }

    private function slugish(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        return Str::slug($v);
    }

    private function firstSlug(array $values): ?string
    {
        foreach ($values as $v) {
            $s = $this->slugish(is_string($v) ? $v : (is_null($v) ? null : (string) $v));
            if ($s) return $s;
        }
        return null;
    }
}