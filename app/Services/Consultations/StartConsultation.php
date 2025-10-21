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
        // If a session already exists for this order, reuse it but do NOT return early.
        // We still want to reattach answers and sync order/pending snapshots below.
        $session = ConsultationSession::where('order_id', $order->id)->first();

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

        // Ensure the session is tied to a user (column is NOT NULL in DB)
        if (empty($order->user_id)) {
            throw new \RuntimeException('Cannot start consultation because order has no user_id. Link the order to a user.');
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

        // Reuse existing session for this order if one exists; otherwise create a new one
        if (!$session) {
            $session = ConsultationSession::firstOrCreate(
                ['order_id' => (int) $order->id],
                [
                    'user_id'   => (int) $order->user_id,
                    'service'   => (string) $service,
                    'treatment' => (string) $treat,
                    'templates' => $snapshot,
                    'steps'     => array_values($stepKeys),
                    'current'   => 0,
                ]
            );
        }

        // Denormalise essential info back onto the order->meta so downstream UIs can rely on it
        $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

        // --- Reattach assessment answers (no cross-session hydration) ---
        // 1) Prefer answers from the PENDING order (same reference) snapshot.
        // 2) If missing, read ONLY the CURRENT session DB row.
        // 3) As a last resort, use answers already present on the APPROVED order snapshot.
        $answers = null;

        // (1) PendingOrder (same reference only)
        try {
            $pending = \App\Models\PendingOrder::where('reference', $order->reference)->first();
            if ($pending) {
                $pm = is_array($pending->meta) ? $pending->meta : (json_decode($pending->meta ?? '[]', true) ?: []);
                $answers = \Illuminate\Support\Arr::get($pm, 'assessment.answers')
                        ?? \Illuminate\Support\Arr::get($pm, 'assessment_snapshot');
            }
        } catch (\Throwable $e) {
            \Log::warning('Unable to read PendingOrder answers for same reference: ' . $e->getMessage());
        }

        // (2) Current-session DB row only
        if (empty($answers)) {
            try {
                $existing = \DB::table('consultation_form_responses')
                    ->where('consultation_session_id', $session->id)
                    ->whereIn('form_type', ['assessment','intake','risk_assessment'])
                    ->orderByDesc('id')
                    ->value('data');

                if ($existing) {
                    $answers = is_string($existing)
                        ? (json_decode($existing, true) ?: [])
                        : $existing;
                }
            } catch (\Throwable $e) {
                \Log::warning('Unable to read current-session answers: ' . $e->getMessage());
            }
        }

        // (3) ApprovedOrder snapshot (fallback only; do not prefer this)
        if (empty($answers)) {
            $answers = Arr::get($meta, 'assessment.answers')
                    ?? Arr::get($meta, 'assessment_snapshot');
        }

        if (!empty($answers)) {
            // Attach to order snapshot
            $meta['assessment'] = $meta['assessment'] ?? [];
            $meta['assessment']['answers'] = $answers;
            // Also keep an immutable snapshot for admin UIs
            $meta['assessment_snapshot'] = $answers;

            // Persist to this session's consultation_form_responses row (idempotent)
            try {
                \DB::table('consultation_form_responses')->updateOrInsert(
                    ['consultation_session_id' => $session->id, 'form_type' => 'risk_assessment'],
                    [
                        'clinic_form_id' => null,
                        'data'          => json_encode($answers),
                        'is_complete'   => 1,
                        'completed_at'  => now(),
                        'updated_at'    => now(),
                        'created_at'    => now(),
                    ]
                );
            } catch (\Throwable $e) {
                \Log::warning('Unable to persist answers for this session: ' . $e->getMessage());
            }
        }

        // Ensure we can link back to the session later (used by Completed Order details & PDFs)
        $meta['consultation_session_id'] = $session->id;

        // Keep PendingOrder snapshot in sync so the admin card always renders answers
        try {
            $pending = \App\Models\PendingOrder::where('reference', $order->reference)->first()
                ?: \App\Models\PendingOrder::where('user_id', $order->user_id)->latest()->first();

            if ($pending) {
                $pm = is_array($pending->meta) ? $pending->meta : (json_decode($pending->meta ?? '[]', true) ?: []);
                $pm['consultation_session_id'] = $session->id;

                if (!empty($answers)) {
                    $pm['assessment'] = $pm['assessment'] ?? [];
                    $pm['assessment']['answers'] = $answers;
                }

                $pending->meta = $pm;
                $pending->save();
            }
        } catch (\Throwable $e) {
            \Log::warning('Unable to sync PendingOrder snapshot: ' . $e->getMessage());
        }

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