@php
    // Prefer upstream-provided answers if any
    $answers = [];
    if (isset($answersForCard) && is_array($answersForCard)) {
        $answers = $answersForCard;
    } elseif (isset($answers) && is_array($answers)) {
        $answers = $answers;
    }

    // Resolve a session-like object
    $sessionLike = $consultationSession ?? $session ?? $consultation ?? $record ?? null;

    // Determine service/treatment for scoping
    $serviceFor = $serviceSlugForForm ?? ($sessionLike->service_slug ?? $sessionLike->service ?? null);
    $treatFor   = $treatmentSlugForForm ?? ($sessionLike->treatment_slug ?? $sessionLike->treatment ?? null);

    // Prefer stored RAF answers from ConsultationFormResponse for this session
    $respQ = \App\Models\ConsultationFormResponse::query()
        ->where('consultation_session_id', $sessionLike?->id)
        ->where('form_type', 'raf');
    if ($serviceFor) { $respQ->where('service_slug', $serviceFor); }
    if ($treatFor)   { $respQ->where('treatment_slug', $treatFor); }

    $resp = $respQ->latest('id')->first();
    if ($resp) {
        $raw = $resp->data;
        if (is_array($raw)) {
            $answers = $answers ?: ($raw['answers'] ?? $raw);
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $answers = $answers ?: ($decoded['answers'] ?? $decoded);
            }
        }
    }

    // Fallback answers from session/order meta
    $toArray = function ($v) {
        if (is_array($v)) return $v; if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; } return [];
    };
    if (empty($answers) && $sessionLike && isset($sessionLike->meta)) {
        $m = $toArray($sessionLike->meta);
        $answers = $m['answers'] ?? data_get($m, 'assessment.answers', []);
    }
    if (empty($answers)) {
        $orderLike = $order ?? ($sessionLike->order ?? null) ?? null;
        if ($orderLike && isset($orderLike->meta)) {
            $om = $toArray($orderLike->meta);
            $answers = $om['answers'] ?? data_get($om, 'assessment.answers', []) ?? [];
        }
    }

    // Find the active RAF ClinicForm matching the current service/treatment
    $form = \App\Models\ClinicForm::query()
        ->where('form_type', 'raf')
        ->when($serviceFor, fn($q) => $q->where('service_slug', $serviceFor))
        ->when($treatFor,   fn($q) => $q->where('treatment_slug', $treatFor))
        ->where('is_active', 1)
        ->orderByDesc('version')
        ->first();

    $schema = is_array($form?->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
@endphp

{{-- Prefer the dedicated RAF Builder view if available; fall back to shared form --}}
@php
    $builderView = collect([
        // common locations
        'consultations.raf-builder',
        'consultations.tabs.raf-builder',
        'consultations.pages.raf-builder',
        'consultations.raf',

        // alternate typo/alias variants that may exist in this project
        'consultations.ref-builder',
        'consultations.tabs.ref-builder',
        'consultations.pages.ref-builder',
    ])->first(function ($cand) { return view()->exists($cand); });
@endphp

@if ($builderView)
    @include($builderView, [
        // session aliases the builder might reference
        'session'              => $sessionLike ?? $session,
        'consultationSession'  => $sessionLike ?? $session,
        'consultation'         => $sessionLike ?? $session,
        'record'               => $sessionLike ?? $session,

        // scoping
        'serviceSlugForForm'   => $serviceFor,
        'treatmentSlugForForm' => $treatFor,

        // answers under multiple keys for compatibility
        'answersForCard'       => $answers ?? [],
        'answers'              => $answers ?? [],

        // optional extras
        'form'                 => $form,
        'schema'               => $schema,
        'mode'                 => 'edit',
        'readonly'             => false,
        'viewOnly'             => false,
        'embedded'             => true,
    ])
@else
    @include('consultations._form', [
        'session'  => $sessionLike ?? $session,
        'slug'     => 'risk-assessment',
        'form'     => $form,
        'schema'   => $schema,
        'oldData'  => $answers ?? [],
        'readonly' => false,
        'viewOnly' => false,
        'mode'     => 'edit',
        'showTitle'=> false,
    ])
@endif