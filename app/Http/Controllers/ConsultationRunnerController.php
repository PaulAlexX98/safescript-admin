<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use Illuminate\Support\Str;

class ConsultationRunnerController extends Controller
{

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

        return view($view, [
            'session' => $session,
            // these help the blade pick the right ClinicForm variant if needed
            'serviceSlugForForm'   => $session->service_slug ?? $session->service ?? null,
            'treatmentSlugForForm' => $session->treatment_slug ?? $session->treatment ?? null,
        ]);
    }

    /**
     * Decide if this session belongs to a reorder flow using session/order metadata.
     */
    private function detectReorder(ConsultationSession $session): bool
    {
        $meta = is_array($session->meta ?? null)
            ? $session->meta
            : (json_decode($session->meta ?? '[]', true) ?: []);

        if ($this->isReorderMeta($meta)) {
            return true;
        }

        // fallback try to infer from related ApprovedOrder if we can find it
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
            'type',
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