<?php

namespace App\Services\Consultations;

use RuntimeException;
use App\Models\PendingOrder;
use Throwable;
use Log;
use DB;
use App\Models\ApprovedOrder;
use App\Models\Service;
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
            throw new RuntimeException('Missing treatment for this service; cannot start consultation.');
        }

        // Ensure the session is tied to a user (column is NOT NULL in DB)
        if (empty($order->user_id)) {
            throw new RuntimeException('Cannot start consultation because order has no user_id. Link the order to a user.');
        }

        // Helper to fetch a specific form_type with sensible fallbacks:
        // 1) exact match service + treatment
        // 2) service-only generic (no treatment)
        // 3) global generic (no service, no treatment)
        $pick = function (string $type) use ($service, $treat) {
            $aliases = [
                'raf'         => ['raf'],
                'advice'      => ['advice', 'consultation_advice'],
                'declaration' => ['pharmacist_declaration', 'declaration'],
                'supply'      => ['supply', 'record_of_supply', 'record-of-supply'],
                'reorder'     => ['reorder'],
            ];
            $types = $aliases[$type] ?? [$type];

            $base = fn () => \App\Models\ClinicForm::query()
                ->where('is_active', true)
                ->whereIn('form_type', $types)
                ->orderByDesc('version')
                ->orderByDesc('id');

            // 1 exact match
            $q1 = $base();
            $exact = $q1->where('service_slug', $service)->where('treatment_slug', $treat)->first();
            if ($exact) {
                return $exact;
            }

            // 2 service-only generic
            $q2 = $base();
            $serviceOnly = $q2->where('service_slug', $service)
                ->where(function ($q) {
                    $q->whereNull('treatment_slug')->orWhere('treatment_slug', '');
                })
                ->first();
            if ($serviceOnly) {
                return $serviceOnly;
            }

            // 3 global generic
            $q3 = $base();
            $global = $q3->where(function ($q) {
                    $q->whereNull('service_slug')->orWhere('service_slug', '');
                })
                ->where(function ($q) {
                    $q->whereNull('treatment_slug')->orWhere('treatment_slug', '');
                })
                ->first();

            return $global;
        };

        // Determine if this is a reorder style flow
        $isReorder = (bool) (
            Arr::get($order->meta ?? [], 'is_reorder')
            || Arr::get($order->meta ?? [], 'reorder')
            || Str::contains((string) $treat, ['reorder','repeat','maintenance'])
            || Str::contains((string) $service, ['reorder','repeat'])
        );

        // Service-first templates
        $orderedKeys = ['raf', 'advice', 'declaration', 'supply'];
        $templates = array_fill_keys($orderedKeys, null);

        $svc = $order->service ?? optional($order->product)->service ?? Service::where('slug', $service)->first();

        if ($svc) {
            // RAF or Reorder depending on flow
            $templates['raf'] = $isReorder
                ? ($svc->reorderForm ?? null)
                : ($svc->rafForm ?? null);

            $templates['advice']      = $svc->adviceForm ?? null;
            $templates['declaration'] = $svc->pharmacistDeclarationForm ?? null;
            // Record of Supply is mapped to the Clinical Notes assignment
            $templates['supply']      = $svc->clinicalNotesForm ?? null;
        }

        // Fill any missing steps using ClinicForm templates with sensible fallbacks
        if (empty($templates['raf'])) {
            $templates['raf'] = $isReorder ? ($pick('reorder') ?: $pick('raf')) : $pick('raf');
        }
        if (empty($templates['advice'])) {
            $templates['advice'] = $pick('advice');
        }
        if (empty($templates['declaration'])) {
            $templates['declaration'] = $pick('declaration');
        }
        if (empty($templates['supply'])) {
            $templates['supply'] = $pick('supply');
        }

        $available = array_filter($templates, fn ($t) => (bool) $t);
        if (empty($available)) {
            throw new RuntimeException("No consultation templates found for service='{$service}' treatment='" . ($treat ?: 'generic') . "'. Assign service-level forms or create ClinicForm templates.");
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

        // If a session already exists, sync its templates and steps without resetting progress
        if ($session) {
            $session->templates = $snapshot;
            $session->steps     = array_values($stepKeys);
            // keep current pointer in range
            $maxIndex = max(0, count($session->steps) - 1);
            if (! is_int($session->current) || $session->current > $maxIndex) {
                $session->current = 0;
            }
            $session->save();
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
            $pending = PendingOrder::where('reference', $order->reference)->first();
            if ($pending) {
                $pm = is_array($pending->meta) ? $pending->meta : (json_decode($pending->meta ?? '[]', true) ?: []);
                $answers = Arr::get($pm, 'assessment.answers')
                        ?? Arr::get($pm, 'assessment_snapshot');
            }
        } catch (Throwable $e) {
            Log::warning('Unable to read PendingOrder answers for same reference: ' . $e->getMessage());
        }

        // (2) Current-session DB row only
        if (empty($answers)) {
            try {
                $existing = DB::table('consultation_form_responses')
                    ->where('consultation_session_id', $session->id)
                    ->whereIn('form_type', ['assessment','intake','risk_assessment'])
                    ->orderByDesc('id')
                    ->value('data');

                if ($existing) {
                    $answers = is_string($existing)
                        ? (json_decode($existing, true) ?: [])
                        : $existing;
                }
            } catch (Throwable $e) {
                Log::warning('Unable to read current-session answers: ' . $e->getMessage());
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
                DB::table('consultation_form_responses')->updateOrInsert(
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
            } catch (Throwable $e) {
                Log::warning('Unable to persist answers for this session: ' . $e->getMessage());
            }
        }

        // Ensure we can link back to the session later (used by Completed Order details & PDFs)
        $meta['consultation_session_id'] = $session->id;

        // Keep PendingOrder snapshot in sync so the admin card always renders answers
        try {
            $pending = PendingOrder::where('reference', $order->reference)->first()
                ?: PendingOrder::where('user_id', $order->user_id)->latest()->first();

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
        } catch (Throwable $e) {
            Log::warning('Unable to sync PendingOrder snapshot: ' . $e->getMessage());
        }

        // Snapshot patient fields (fallback to user record if not in meta)
        $meta['firstName'] = $meta['firstName'] ?? ($order->user->first_name ?? (Arr::get($order->meta ?? [], 'patient.firstName') ?? null));
        $meta['lastName']  = $meta['lastName']  ?? ($order->user->last_name  ?? (Arr::get($order->meta ?? [], 'patient.lastName')  ?? null));
        $meta['email']     = $meta['email']     ?? ($order->user->email      ?? (Arr::get($order->meta ?? [], 'patient.email')     ?? null));
        $meta['phone']     = $meta['phone']     ?? ($order->user->phone      ?? (Arr::get($order->meta ?? [], 'patient.phone')     ?? null));
        $meta['dob']       = $meta['dob']       ?? (Arr::get($order->meta ?? [], 'dateOfBirth')
                                                    ?? Arr::get($order->meta ?? [], 'patient.dob')
                                                    ?? ($order->user->dob ?? null));

        // Snapshot payment status from the column so the UI doesn't have to guess where to read it
        if (!isset($meta['payment_status']) || $meta['payment_status'] === null || $meta['payment_status'] === '') {
            $meta['payment_status'] = (string) ($order->payment_status ?? '');
        }

        // Normalise items so each item has a `variation` string even if only `variations/optionLabel/dose/strength` were set
        $items = Arr::get($meta, 'items')
              ?? Arr::get($meta, 'line_items')
              ?? Arr::get($meta, 'lines')
              ?? Arr::get($meta, 'cart.items');

        if (empty($items)) {
            $sp = Arr::get($meta, 'selectedProduct') ?? [];
            if (!empty($sp)) {
                $items = [[
                    'name'       => $sp['name'] ?? (Arr::get($sp, 'title', 'Item')),
                    'qty'        => (int) ($sp['qty'] ?? 1),
                    'variation'  => (string) (Arr::get($sp, 'variation')
                                        ?? Arr::get($sp, 'variations')
                                        ?? Arr::get($sp, 'optionLabel')
                                        ?? Arr::get($sp, 'variant')
                                        ?? Arr::get($sp, 'dose')
                                        ?? Arr::get($sp, 'strength')
                                        ?? ''),
                    'unitMinor'  => Arr::get($sp, 'unitMinor'),
                    'totalMinor' => Arr::get($sp, 'totalMinor'),
                ]];
            }
        }

        if (is_array($items)) {
            foreach ($items as &$it) {
                if (empty($it['variation'])) {
                    $it['variation'] = (string) (Arr::get($it, 'variation')
                                        ?? Arr::get($it, 'variations')
                                        ?? Arr::get($it, 'optionLabel')
                                        ?? Arr::get($it, 'variant')
                                        ?? Arr::get($it, 'dose')
                                        ?? Arr::get($it, 'strength')
                                        ?? '');
                }
                // keep qty sane
                if (!isset($it['qty'])) {
                    $it['qty'] = (int) (Arr::get($it, 'quantity', 1));
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