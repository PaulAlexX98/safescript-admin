<?php

namespace App\Services\Consultations;

use RuntimeException;
use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Support\Arrayable;
use App\Models\PendingOrder;
use App\Models\ApprovedOrder;
use App\Models\Service;
use App\Models\ClinicForm;
use App\Models\ConsultationSession;

class StartConsultation
{
    /**
     * Safely coerce JSON-or-array values to an array without double-decoding.
     * Handles arrays, stdClass, JSON strings, Collections, Arrayable.
     */
    private function arr($v): array
    {
        if (is_array($v)) return $v;

        if ($v instanceof \ArrayObject) return $v->getArrayCopy();
        if ($v instanceof Arrayable) return $v->toArray();
        if ($v instanceof \stdClass) return (array) $v;
        if ($v instanceof Collection) return $v->toArray();

        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') return [];
            try {
                // Only decode strings. Never attempt to decode arrays/objects.
                $d = json_decode($s, true, 512, JSON_THROW_ON_ERROR);
                return is_array($d) ? $d : [];
            } catch (Throwable $e) {
                return [];
            }
        }

        return [];
    }

    private function decodeToArray($value): array
    {
        // Delegate to the existing safe coercion helper to avoid double-decoding
        return $this->arr($value);
    }

    /**
     * Recursively build a map of field key -> label from a ClinicForm schema array.
     */
    private function buildLabelMapFromSchema($schema): array
    {
        $map = [];

        $walk = function ($node) use (&$walk, &$map) {
            if ($node instanceof \stdClass) {
                $node = (array) $node;
            }
            if (! is_array($node)) {
                return;
            }

            $type  = strtolower((string) (Arr::get($node, 'type', '') ?? ''));
            $key   = Arr::get($node, 'name')
                    ?? Arr::get($node, 'key')
                    ?? Arr::get($node, 'data.key');
            $label = Arr::get($node, 'data.label')
                    ?? Arr::get($node, 'label');

            // input-ish nodes: if they have a key/name and a label, record it
            if ($key && $label && ! isset($map[$key])) {
                $map[(string) $key] = (string) $label;
            }

            // traverse common child holders
            foreach (['schema','components','fields','children','columns','rows'] as $childKey) {
                $maybe = $node[$childKey] ?? null;
                if (is_array($maybe)) {
                    foreach ($maybe as $child) {
                        $walk($child);
                    }
                }
            }

            // also traverse any nested arrays generically
            foreach ($node as $k => $v) {
                if (is_array($v) || $v instanceof \stdClass) {
                    $walk($v);
                }
            }
        };

        $walk($schema);
        return $map;
    }

    /**
     * Convert a flat answers array into QA rows using an optional label map.
     */
    private function toQaRows(array $answers, array $labelMap = []): array
    {
        $out = [];
        foreach ($answers as $k => $v) {
            $label = $labelMap[$k] ?? Str::headline((string) $k);
            $out[] = [
                'key'      => (string) $k,
                'question' => (string) $label,
                'answer'   => $v,
            ];
        }
        return $out;
    }

    public function __invoke(ApprovedOrder $order, array $context = []): ConsultationSession
    {
        // Reuse an existing session for this order if present.
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

        if (! $treat) {
            throw new RuntimeException('Missing treatment for this service; cannot start consultation.');
        }

        if (empty($order->user_id)) {
            throw new RuntimeException('Cannot start consultation because order has no user_id. Link the order to a user.');
        }

        // Resolve explicit intent if provided by caller or meta
        $intent = null; // reorder | nhs | new | risk_assessment
        $rawCtx = strtolower((string) ($context['desired_type'] ?? ''));
        if ($rawCtx !== '') {
            $intent = in_array($rawCtx, ['risk-assessment','risk_assessment','raf'], true) ? 'risk_assessment' : $rawCtx;
        }
        if ($intent === null || $intent === '') {
            $m = $this->decodeToArray($order->meta);
            $cType = strtolower((string) (Arr::get($m, 'consultation.type') ?: Arr::get($m, 'consultation.mode') ?: Arr::get($m, 'type') ?: ''));
            if ($cType !== '') {
                $intent = in_array($cType, ['risk-assessment','risk_assessment','raf'], true) ? 'risk_assessment' : $cType;
            }
        }
        if ($intent === null || $intent === '') {
            $intent = 'risk_assessment';
        }

        // Helper to fetch a specific form_type with sensible fallbacks:
        $pick = function (string $type) use ($service, $treat) {
            $aliases = [
                'raf'         => ['raf'],
                'assessment'  => ['assessment','risk_assessment','risk-assessment','intake'],
                'advice'      => ['advice', 'consultation_advice'],
                'declaration' => ['pharmacist_declaration', 'declaration'],
                'supply'      => ['supply', 'record_of_supply', 'record-of-supply'],
                'reorder'     => ['reorder'],
            ];
            $types = $aliases[$type] ?? [$type];

            $base = fn () => ClinicForm::query()
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
        $isReorder = ($intent === 'reorder');
        if (! $isReorder) {
            $isReorder = (bool) (
                Arr::get($order->meta ?? [], 'is_reorder')
                || Arr::get($order->meta ?? [], 'reorder')
                || Str::contains((string) $treat, ['reorder','repeat'])
                || Str::contains((string) $service, ['reorder','repeat'])
            );
        }

        // Service-first templates and step order
        if ($isReorder) {
            $orderedKeys = ['reorder','advice','declaration','supply'];
        } else {
            $orderedKeys = ['raf','assessment','advice','declaration','supply'];
        }
        $templates = array_fill_keys($orderedKeys, null);

        $svc = $order->service ?? optional($order->product)->service ?? Service::where('slug', $service)->first();

        if ($svc) {
            if ($isReorder) {
                // Reorder-first flow: first step is the Reorder form
                $templates['reorder']    = $svc->reorderForm ?? null;
            } else {
                // New consultation flow: first step is RAF, then optional assessment
                $templates['raf']        = $svc->rafForm ?? null;
                if (empty($templates['assessment']) && method_exists($svc, 'assessmentForm')) {
                    $templates['assessment'] = $svc->assessmentForm ?: null;
                }
            }

            $templates['advice']      = $svc->adviceForm ?? null;
            $templates['declaration'] = $svc->pharmacistDeclarationForm ?? null;
            // Record of Supply is mapped to the Clinical Notes assignment
            $templates['supply']      = $svc->clinicalNotesForm ?? null;
        }

        // Fill any missing steps using ClinicForm templates with sensible fallbacks
        if ($isReorder) {
            if (empty($templates['reorder'])) {
                // Prefer a true reorder template, otherwise fall back to RAF if configured that way
                $templates['reorder'] = $pick('reorder') ?: $pick('raf');
            }
        } else {
            if (empty($templates['raf'])) {
                $templates['raf'] = $pick('raf');
            }
            if (empty($templates['assessment'])) {
                $templates['assessment'] = $pick('assessment');
            }
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

            // ensure schema is an array without double-decoding
            $schemaArr = $this->decodeToArray($form->schema);

            $snapshot[$key] = [
                'id'      => $form->id,
                'name'    => $form->name,
                'version' => $form->version,
                'schema'  => $schemaArr,
            ];
        }

        // If a session already exists, sync its templates and steps without resetting progress
        // Upsert the session with safe arrays to avoid json_decode on arrays
        $maxIndex     = max(0, count($stepKeys) - 1);
        $currentIndex = (is_int($session?->current) && $session->current <= $maxIndex) ? $session->current : 0;

        // Persist intent onto existing session meta so downstream resolvers stay deterministic
        $sessMeta = (array) ($session?->meta ?? []);
        data_set($sessMeta, 'consultation.type', $isReorder ? 'reorder' : 'risk_assessment');
        data_set($sessMeta, 'consultation.mode', $isReorder ? 'reorder' : 'risk_assessment');

        $payload = [
            'user_id'   => (int) $order->user_id,
            'service'   => (string) $service,
            'treatment' => (string) $treat,
            'templates' => (array) ($snapshot ?? []),
            'steps'     => array_values((array) ($stepKeys ?? [])),
            'current'   => $currentIndex,
            'meta'      => (array) ($sessMeta ?? []),
        ];

        $session = ConsultationSession::updateOrCreate(
            ['order_id' => (int) $order->id],
            $payload
        );

        // If the session has a form_type column, persist it for router normalisation
        try {
            if (property_exists($session, 'form_type')) {
                $session->form_type = $isReorder ? 'reorder' : 'risk_assessment';
                $session->save();
            }
        } catch (\Throwable $e) {
            // ignore if not present
        }

        // Denormalise essential info back onto the order->meta so downstream UIs can rely on it
        $meta = $this->decodeToArray($order->meta);

        // --- Reattach assessment answers (no cross-session hydration) ---
        $answers = null;

        // (1) PendingOrder (same reference only)
        try {
            $pending = PendingOrder::where('reference', $order->reference)->first();
            if ($pending) {
                $pm = $this->decodeToArray($pending->meta);
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

                if ($existing !== null) {
                    $answers = $this->decodeToArray($existing);
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

        // Force-normalise $answers to an array before any downstream use
        $answers = $this->decodeToArray($answers);

        if (! empty($answers)) {
            // Choose a schema to label questions: prefer assessment, then raf
            $assessmentSchemaSnap = $snapshot['assessment']['schema'] ?? [];
            $rafSchemaSnap        = $snapshot['raf']['schema'] ?? [];
            $labelMap = [];
            if (! empty($assessmentSchemaSnap)) {
                $labelMap = $this->buildLabelMapFromSchema($assessmentSchemaSnap);
            } elseif (! empty($rafSchemaSnap)) {
                $labelMap = $this->buildLabelMapFromSchema($rafSchemaSnap);
            }

            // Build QA rows for admin rendering
            $qaRows = $this->toQaRows(is_array($answers) ? $answers : [], $labelMap);

            // Attach to order snapshot
            $meta['assessment'] = $meta['assessment'] ?? [];
            $meta['assessment']['answers'] = $answers;
            $meta['assessment']['qa']      = $qaRows; // convenient inline QA list

            // Also surface under formsQA for all UIs that expect this shape
            $meta['formsQA'] = $meta['formsQA'] ?? [];
            $meta['formsQA']['assessment'] = [
                'answers'     => is_array($answers) ? $answers : [],
                'qa'          => $qaRows,
                'schema'      => $assessmentSchemaSnap ?: $rafSchemaSnap,
                'form_type'   => 'assessment',
                'service_slug'=> $service,
            ];

            // Keep an immutable snapshot
            $meta['assessment_snapshot'] = $answers;

            // Persist to this session's consultation_form_responses row (idempotent) for assessment (not reorder)
            if (! $isReorder) {
                try {
                    $payloadForData = [
                        'source'   => 'admin',
                        'received' => now()->toIso8601String(),
                        'answers'  => is_array($answers) ? $answers : [],
                    ];

                    DB::table('consultation_form_responses')->updateOrInsert(
                        ['consultation_session_id' => $session->id, 'form_type' => 'assessment'],
                        [
                            'clinic_form_id' => $templates['assessment']['id'] ?? null,
                            'step_slug'      => 'assessment',
                            'service_slug'   => $service,
                            'treatment_slug' => $treat,
                            'answers'        => json_encode(is_array($answers) ? $answers : []),
                            'data'           => json_encode($payloadForData),
                            'is_complete'    => 1,
                            'completed_at'   => now(),
                            'updated_at'     => now(),
                            'created_at'     => DB::raw('COALESCE(created_at, NOW())'),
                        ]
                    );
                } catch (Throwable $e) {
                    Log::warning('Unable to persist assessment answers for this session: ' . $e->getMessage());
                }
            }
        }

        // Ensure we can link back to the session later (used by Completed Order details & PDFs)
        $meta['consultation_session_id'] = $session->id;

        // Keep PendingOrder snapshot in sync so the admin card always renders answers
        try {
            $pending = PendingOrder::where('reference', $order->reference)->first()
                ?: PendingOrder::where('user_id', $order->user_id)->latest()->first();

            if ($pending) {
                $pm = $this->decodeToArray($pending->meta);
                $pm['consultation_session_id'] = $session->id;

                if (! empty($answers)) {
                    $pm['assessment'] = $pm['assessment'] ?? [];
                    $pm['assessment']['answers'] = $answers;

                    $pm['formsQA'] = $pm['formsQA'] ?? [];
                    $pm['formsQA']['assessment'] = $pm['formsQA']['assessment'] ?? [];
                    $pm['formsQA']['assessment']['answers'] = $answers;
                    $pm['formsQA']['assessment']['form_type'] = 'assessment';
                    $pm['formsQA']['assessment']['service_slug'] = $service;
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
        if (! isset($meta['payment_status']) || $meta['payment_status'] === null || $meta['payment_status'] === '') {
            $meta['payment_status'] = (string) ($order->payment_status ?? '');
        }

        // Normalise items so each item has a `variation` string even if only `variations/optionLabel/dose/strength` were set
        $items = Arr::get($meta, 'items')
              ?? Arr::get($meta, 'line_items')
              ?? Arr::get($meta, 'lines')
              ?? Arr::get($meta, 'cart.items');

        if (empty($items)) {
            $sp = Arr::get($meta, 'selectedProduct') ?? [];
            if (! empty($sp)) {
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
                if (! isset($it['qty'])) {
                    $it['qty'] = (int) (Arr::get($it, 'quantity', 1));
                }
                if ($it['qty'] < 1) {
                    $it['qty'] = 1;
                }
            }
            unset($it);
            $meta['items'] = array_values($items);
        }

        $meta['consultation'] = $meta['consultation'] ?? [];
        $meta['consultation']['type'] = $isReorder ? 'reorder' : 'risk_assessment';
        $meta['consultation']['mode'] = $isReorder ? 'reorder' : 'risk_assessment';
        $order->meta = $meta;
        $order->save();

        return $session;
    }

    private function slugish(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        return Str::slug($v);
    }

    private function firstSlug(array $values): ?string
    {
        foreach ($values as $v) {
            $s = $this->slugish(is_string($v) ? $v : (is_null($v) ? null : (string) $v));
            if ($s) {
                return $s;
            }
        }
        return null;
    }
}