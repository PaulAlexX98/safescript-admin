<?php

namespace App\Services\Consultations;

use App\Models\ClinicForm;
use App\Models\ConsultationSession;
use App\Models\ApprovedOrder;
use Illuminate\Support\Arr;

class StartConsultation
{
    /**
     * Create (or reuse) a consultation session for an ApprovedOrder.
     */
    public function __invoke(ApprovedOrder $order): ConsultationSession
    {
        // Reuse if one already exists
        if ($existing = ConsultationSession::where('order_id', $order->id)->first()) {
            return $existing;
        }

        // Try to resolve service/treatment from order fields or meta
        $service = $this->slugish(
            $order->service_slug
                ?? $order->service
                ?? $order->service_name
                ?? Arr::get($order->meta ?? [], 'service.slug')
                ?? Arr::get($order->meta ?? [], 'service')
                ?? 'weight-management' // safe default
        );

        $treat = $this->slugish(
            $order->treatment_slug
                ?? $order->treatment
                ?? $order->treatment_name
                ?? Arr::get($order->meta ?? [], 'treatment.slug')
                ?? Arr::get($order->meta ?? [], 'treatment')
                ?? '' // generic
        );

        // Helper to pick a template from clinic_forms
        $pick = function (string $type) use ($service, $treat) {
            $q = ClinicForm::query()
                ->where('form_type', $type)
                ->where('service_slug', $service)
                ->where('is_active', true);

            // prefer treatment-specific, fallback to generic (NULL)
            if ($treat !== '') {
                $q->where(function ($qq) use ($treat) {
                    $qq->where('treatment_slug', $treat)->orWhereNull('treatment_slug');
                });
            } else {
                $q->whereNull('treatment_slug');
            }

            return $q->orderByRaw('treatment_slug is null') // non-null first
                    ->orderByDesc('version')
                    ->first();
        };

        $map = [
            'raf'         => $pick('raf'),
            'advice'      => $pick('advice'),
            'declaration' => $pick('declaration'),
            'supply'      => $pick('supply'),
        ];

        foreach ($map as $key => $form) {
            if (!$form) {
                throw new \RuntimeException("Missing template [$key] for service='{$service}' treatment='".($treat ?: 'generic')."'.");
            }
        }

        // Snapshot templates used in this session
        $snapshot = [];
        foreach ($map as $key => $form) {
            $snapshot[$key] = [
                'id'      => $form->id,
                'name'    => $form->name,
                'version' => $form->version,
                'schema'  => $form->schema, // saved builder blocks
            ];
        }

        return ConsultationSession::create([
            'order_id'          => $order->id,
            'patient_id'        => $order->patient_id ?? null,
            'service_slug'      => $service,
            'treatment_slug'    => $treat ?: null,
            'status'            => 'in_progress',
            'current_step'      => 1,
            'step_keys'         => array_keys($snapshot),     // ["raf","advice","declaration","supply"]
            'template_snapshot' => $snapshot,
            'answers'           => (object) [],
            'started_at'        => now(),
        ]);
    }

    private function slugish($val): string
    {
        $s = is_string($val) ? trim($val) : (is_scalar($val) ? trim((string) $val) : '');
        return str_replace(' ', '-', strtolower($s));
    }
}