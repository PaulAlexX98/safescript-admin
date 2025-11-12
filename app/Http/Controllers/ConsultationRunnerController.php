<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use App\Models\ClinicForm;
use App\Models\Service;
use App\Models\Treatment;

class ConsultationRunnerController extends Controller
{

    /**
     * Resolve a ClinicForm id for the given step using this precedence:
     * 1) Session templates snapshot (session->templates or meta.templates)
     * 2) Treatment-level assignment on the current treatment
     * 3) Service-level assignment on the current service
     * When resolved from 2 or 3, persist meta.templates[step] so future loads are deterministic.
     */
    private function resolveClinicFormId(ConsultationSession $session, string $step): ?int
    {
        $step = Str::slug($step); // e.g. "reorder" or "risk-assessment"

        // 1) Session templates snapshot (supports either scalar id or ['id'=>..])
        $snap = is_array($session->templates ?? null)
            ? $session->templates
            : (json_decode($session->templates ?? '[]', true) ?: []);

        if (isset($snap[$step])) {
            $candidate = $snap[$step];
            if (is_numeric($candidate)) return (int) $candidate;
            if (is_array($candidate) && isset($candidate['id']) && is_numeric($candidate['id'])) return (int) $candidate['id'];
        }

        // 1b) meta.templates shorthand
        $meta = is_array($session->meta ?? null)
            ? $session->meta
            : (json_decode($session->meta ?? '[]', true) ?: []);
        $metaId = Arr::get($meta, "templates.$step");
        if (is_numeric($metaId)) return (int) $metaId;

        // 2) Treatment-level then 3) Service-level
        $svcSlug = $session->service_slug ?? $session->service ?? null;
        $trtSlug = $session->treatment_slug ?? $session->treatment ?? null;

        $service = $svcSlug ? Service::where('slug', (string) $svcSlug)->first() : null;
        $treatment = null;
        if ($trtSlug) {
            $treatment = $service?->treatments()->where('slug', (string) $trtSlug)->first();
            if (!$treatment && class_exists(Treatment::class)) {
                $treatment = Treatment::where('slug', (string) $trtSlug)->first();
            }
        }

        $relation = match ($step) {
            'reorder' => 'reorderForm',
            'risk-assessment', 'raf' => 'rafForm',
            default => null,
        };

        $resolvedId = null;
        if ($relation) {
            if ($treatment && method_exists($treatment, $relation) && $treatment->{$relation}) {
                $form = $treatment->{$relation};
                if ($form instanceof ClinicForm) $resolvedId = (int) $form->id; elseif (is_object($form) && isset($form->id)) $resolvedId = (int) $form->id;
            }
            if (!$resolvedId && $service && method_exists($service, $relation) && $service->{$relation}) {
                $form = $service->{$relation};
                if ($form instanceof ClinicForm) $resolvedId = (int) $form->id; elseif (is_object($form) && isset($form->id)) $resolvedId = (int) $form->id;
            }
        }

        // Persist to meta.templates for determinism next time
        if ($resolvedId) {
            Arr::set($meta, "templates.$step", $resolvedId);
            $session->meta = $meta;
            try { $session->save(); } catch (\Throwable $e) {}
        }

        return $resolvedId;
    }

    public function pharmacistAdvice(ConsultationSession $session)
    {
        return $this->jump($session, 'pharmacist-advice');
    }

    public function pharmacistDeclaration(ConsultationSession $session)
    {
        return $this->jump($session, 'pharmacist-declaration');
    }

    public function recordOfSupply(ConsultationSession $session)
    {
        return $this->jump($session, 'record-of-supply');
    }

    public function riskAssessment(ConsultationSession $session)
    {
        return $this->jump($session, 'risk-assessment');
    }

    public function patientDeclaration(ConsultationSession $session)
    {
        return $this->jump($session, 'patient-declaration');
    }

    public function reorder(ConsultationSession $session)
    {
        return $this->jump($session, 'reorder');
    }

    public function start(ConsultationSession $session)
    {
        // Honour explicit query first
        $explicit = null;
        try {
            $q = request()->query('type') ?? request()->query('mode');
            if (is_string($q)) {
                $explicit = strtolower($q);
            }
        } catch (\Throwable $e) {
            // not in HTTP context
        }

        if (is_string($explicit) && $explicit !== '') {
            $normalized = in_array($explicit, ['risk-assessment','risk_assessment','raf'], true)
                ? 'risk_assessment'
                : $explicit;

            // Persist the chosen intent for downstream resolvers
            $meta = is_array($session->meta ?? null)
                ? $session->meta
                : (json_decode($session->meta ?? '[]', true) ?: []);
            data_set($meta, 'consultation.type', $normalized);
            data_set($meta, 'consultation.mode', $normalized);
            $session->meta = $meta;
            try { $session->save(); } catch (\Throwable $e) {}

            return $this->jump($session, $normalized === 'reorder' ? 'reorder' : 'risk-assessment');
        }

        // Fall back to heuristic detection
        $isReorder = $this->detectReorder($session);
        return $this->jump($session, $isReorder ? 'reorder' : 'risk-assessment');
    }

    /**
     * Shared view jumpper that maps step slugs to blade views and passes useful context.
     */
    private function jump(ConsultationSession $session, string $step)
    {
        $map = [
            'risk-assessment'         => 'consultations.risk-assessment',
            'reorder'                 => 'consultations.reorder',
            'pharmacist-advice'       => 'consultations.pharmacist-advice',
            'pharmacist-declaration'  => 'consultations.pharmacist-declaration',
            'record-of-supply'        => 'consultations.record-of-supply',
            'patient-declaration'     => 'consultations.patient-declaration',
        ];
        $view = $map[$step] ?? $map['risk-assessment'];

        // Persist type intent onto the session meta so downstream resolvers remain deterministic
        $meta = is_array($session->meta ?? null)
            ? $session->meta
            : (json_decode($session->meta ?? '[]', true) ?: []);

        $intent = $step === 'reorder' ? 'reorder' : 'risk_assessment';
        try {
            $q = request()->query('type') ?? request()->query('mode');
            if (is_string($q) && $q !== '') {
                $sv = Str::slug($q);
                if (str_contains($sv, 'reorder')) $intent = 'reorder';
                if (in_array($sv, ['risk-assessment','risk_assessment','raf'], true)) $intent = 'risk_assessment';
            }
        } catch (\Throwable $e) {}

        data_set($meta, 'consultation.type', $intent);
        data_set($meta, 'consultation.mode', $intent);
        $session->meta = $meta;
        try { $session->save(); } catch (\Throwable $e) {}

        // Resolve the correct ClinicForm id for this step and pass it to the view
        $stepKey = Str::slug($step);
        $templateId = $this->resolveClinicFormId($session, $stepKey);
        $clinicForm = $templateId ? ClinicForm::find($templateId) : null;

        return view($view, [
            'session'              => $session,
            'serviceSlugForForm'   => $session->service_slug ?? $session->service ?? null,
            'treatmentSlugForForm' => $session->treatment_slug ?? $session->treatment ?? null,
            'templateId'           => $templateId,
            'clinicForm'           => $clinicForm,
            'formType'             => $stepKey,
        ]);
    }

    /**
     * Decide if this session belongs to a reorder flow using session/order metadata.
     */
    private function detectReorder(ConsultationSession $session): bool
    {
        // 1) Explicit request hint wins
        try {
            $q = request()->query('type') ?? request()->query('mode');
            if (is_string($q) && $q !== '') {
                $sv = Str::slug($q);
                if (str_contains($sv, 'reorder') || str_contains($sv, 'repeat') || str_contains($sv, 'refill') || str_contains($sv, 'maintenance')) {
                    return true;
                }
                if (in_array($sv, ['risk-assessment','risk_assessment','raf'], true)) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 2) Session property if present
        if (property_exists($session, 'form_type') && is_string($session->form_type)) {
            $sv = Str::slug($session->form_type);
            if (str_contains($sv, 'reorder') || str_contains($sv, 'repeat') || str_contains($sv, 'refill') || str_contains($sv, 'maintenance')) {
                return true;
            }
            if (in_array($sv, ['risk-assessment','risk_assessment','raf'], true)) {
                return false;
            }
        }

        // 3) Session meta
        $meta = is_array($session->meta ?? null)
            ? $session->meta
            : (json_decode($session->meta ?? '[]', true) ?: []);

        if ($this->isReorderMeta($meta)) {
            return true;
        }

        // 4) Fallback try to infer from related ApprovedOrder if we can find it
        try {
            if (class_exists(\App\Models\ApprovedOrder::class)) {
                $ord = \App\Models\ApprovedOrder::where('consultation_session_id', $session->id)->first();
                if (! $ord) {
                    // JSON path lookup for MySQL/MariaDB drivers that expose meta as JSON
                    $ord = \App\Models\ApprovedOrder::where('meta->consultation_session_id', $session->id)->first();
                }
                if (! $ord && ($ref = ($session->reference ?? $session->order_reference ?? null))) {
                    $ord = \App\Models\ApprovedOrder::where('reference', $ref)->first();
                }
                if ($ord) {
                    $m = is_array($ord->meta ?? null) ? $ord->meta : (json_decode($ord->meta ?? '[]', true) ?: []);
                    if ($this->isReorderMeta($m)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // swallow any DB/JSON issues and default to new flow
        }

        return false;
    }

    /**
     * Heuristics to decide reorder based on a meta array.
     */
    private function isReorderMeta(array $meta): bool
    {
        // boolean style hints
        foreach ([
            'is_reorder',
            'isReorder',
            'flags.reorder',
            'reorder',
        ] as $path) {
            $v = data_get($meta, $path);
            if (is_bool($v)) return $v === true;
            if (is_numeric($v)) return ((int) $v) === 1;
            if (is_string($v) && $v !== '') {
                $sv = Str::slug($v);
                if (in_array($sv, ['1','true','yes','reorder','repeat','refill','maintenance'], true)) return true;
            }
        }

        // string style type or flow
        foreach ([
            'consultation.type',
            'consultation.mode',
            'type',
            'mode',
            'flow',
            'order_type',
            'meta.flow',
        ] as $path) {
            $v = data_get($meta, $path);
            if (!is_string($v) || $v === '') continue;
            $sv = Str::slug($v);
            if (str_contains($sv, 'reorder') ||
                str_contains($sv, 'repeat') ||
                str_contains($sv, 'refill') ||
                str_contains($sv, 'maintenance')) {
                return true;
            }
        }

        // product plan hints
        foreach ([
            'selectedProduct.plan',
            'selected_product.plan',
            'selected.plan',
            'plan',
        ] as $path) {
            $v = data_get($meta, $path);
            if (!is_string($v) || $v === '') continue;
            $sv = Str::slug($v);
            if (str_contains($sv, 'repeat') ||
                str_contains($sv, 'refill') ||
                str_contains($sv, 'maintenance') ||
                str_contains($sv, 'reorder')) {
                return true;
            }
        }

        return false;
    }
}