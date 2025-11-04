@php
    /**
     * Bootstrap answers:
     * Prefer upstream-provided arrays then session/order meta fallbacks
     */
    $answers = [];

    // Prefer upstream-provided arrays
    if (isset($answersForCard) && is_array($answersForCard)) {
        $answers = $answersForCard;
    } elseif (isset($answers) && is_array($answers)) {
        $answers = $answers;
    }

    // Find a session-like object
    $sessionLike = $consultationSession
        ?? $session
        ?? $consultation
        ?? $record
        ?? null;

    // Helper to decode meta safely
    $toArray = function ($v) {
        if (is_array($v)) return $v;
        if (is_string($v)) {
            $d = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($d)) return $d;
        }
        return [];
    };

    if (empty($answers)) {
        // 1) Try session->meta.answers
        if ($sessionLike && isset($sessionLike->meta)) {
            $m = $toArray($sessionLike->meta);
            $answers = $m['answers'] ?? data_get($m, 'assessment.answers', []);
        }
    }

    if (empty($answers)) {
        // 2) Try pulling from linked Order meta
        $orderLike = $order ?? ($sessionLike->order ?? null) ?? null;
        if ($orderLike && isset($orderLike->meta)) {
            $om = $toArray($orderLike->meta);
            $answers = $om['answers'] ?? data_get($om, 'assessment.answers', []) ?? [];
        }
    }

    // Ensure array
    if (! is_array($answers)) {
        $answers = $toArray($answers);
    }

    // Resolve RAF ClinicForm with service-first fallbacks
    $serviceFor = $serviceSlugForForm
        ?? ($sessionLike->service_slug ?? $sessionLike->service ?? 'weight-management-service');
    $treatFor   = $treatmentSlugForForm
        ?? ($sessionLike->treatment_slug ?? $sessionLike->treatment ?? 'mounjaro');

    // Normalise to slugs for consistent matching
    $serviceFor = $serviceFor ? \Illuminate\Support\Str::slug($serviceFor) : null;
    $treatFor   = $treatFor ? \Illuminate\Support\Str::slug($treatFor) : null;

    // 1) Exact RAF for this service + treatment
    $form = \App\Models\ClinicForm::query()
        ->where('form_type', 'raf')
        ->when($serviceFor, fn($q) => $q->where('service_slug', $serviceFor))
        ->when($treatFor,   fn($q) => $q->where('treatment_slug', $treatFor))
        ->where('is_active', 1)
        ->orderByDesc('version')->orderByDesc('id')
        ->first();

    // 2) Service-only RAF (no treatment constraint)
    if (! $form && $serviceFor) {
        $form = \App\Models\ClinicForm::query()
            ->where('form_type', 'raf')
            ->where('service_slug', $serviceFor)
            ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
            ->where('is_active', 1)
            ->orderByDesc('version')->orderByDesc('id')
            ->first();
    }

    // 3) Service-assigned RAF via Service relation
    if (! $form && $serviceFor) {
        $svc = \App\Models\Service::query()->where('slug', $serviceFor)->first();
        if ($svc && $svc->rafForm) {
            $form = $svc->rafForm; // use assigned ClinicForm
        }
    }

    // 4) Global RAF with no service/treatment
    if (! $form) {
        $form = \App\Models\ClinicForm::query()
            ->where('form_type', 'raf')
            ->where('is_active', 1)
            ->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
            ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
            ->orderByDesc('version')->orderByDesc('id')
            ->first();
    }

    // Make sure schema is an array (might be empty for legacy/manual RAF)
    $schema = is_array($form?->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);

    // Helper getters so fields prefill with old() or answers[]
    $get = function (string $key, $default = null) use ($answers) {
        $ov = old("answers.$key");
        if ($ov !== null) return $ov;
        if (is_array($answers) && array_key_exists($key, $answers)) return $answers[$key];
        return data_get($answers, $key, $default);
    };

    $csv = function ($v) {
        if (is_array($v)) return implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v));
        if (is_string($v)) return $v;
        return '';
    };

    // Field catalogue for known intake keys: key => [label, type]
    $fields = [
        // About you
        'age_18_to_85' => ['Age 18–85', 'bool'],
        'ethnicity' => ['Ethnicity', 'tags'],
        'pregnant_or_breastfeeding_or_planning' => ['Pregnant / Breastfeeding / Planning', 'bool'],
        'preg_text' => ['Pregnancy / breastfeeding details', 'text'],
        'height_input' => ['Height (input)', 'number'],
        'weight_input' => ['Weight (input)', 'number'],
        'bmi' => ['BMI', 'number'],
        'target_weight' => ['Target Weight', 'text'],

        // Conditions & medicines
        'weight_related_conditions' => ['Weight related conditions', 'tags'],
        'smoke' => ['Smoke', 'bool'],
        'want_stop_smoking_info' => ['Wants stop-smoking info', 'bool'],
        'drink_alcohol' => ['Drink alcohol', 'bool'],
        'want_alcohol_info' => ['Wants alcohol info', 'bool'],
        'prior_weightloss_or_led' => ['Prior WL / LED', 'bool'],
        'prior_weightloss_details' => ['Prior WL / LED details', 'text'],
        'require_evidence_yes' => ['Evidence required', 'bool'],
        'eating_disorder' => ['Easting disorder', 'bool'],
        'eating_disorder_text' => ['Eating disorder details', 'text'],
        'has_conditions_yes' => ['Has conditions (screen)', 'bool'],
        'has_conditions' => ['Conditions', 'tags'],
        'conditions_text' => ['Conditions notes', 'text'],
        'has_medicines_yes' => ['Takes medicines (screen)', 'bool'],
        'has_medicines_list' => ['Medicine taken for 12 days', 'tags'],
        'meds_text' => ['Meds details', 'text'],
        'oral_contraceptives' => ['Oral contraceptives', 'bool'],
        'ocp_details' => ['OCP details', 'text'],
        'exercise_4_5_per_week' => ['Exercise 4–5× per week', 'bool'],
        'exercise_text' => ['Exercise notes', 'text'],
        'daily_calories' => ['Daily calories', 'text'],

        // Past medical history
        'kidney_or_liver_impairment' => ['Kidney or liver impairment', 'bool'],
        'kidney_or_liver_impairment_text' => ['Kidney/Liver details', 'text'],
        'other_medical_conditions_yes' => ['Other medical conditions (screen)', 'bool'],
        'other_medical_conditions_text' => ['Other conditions details', 'text'],
        'current_or_recent_meds_yes' => ['Current or recent meds (screen)', 'bool'],
        'current_or_recent_meds_text' => ['Current/recent meds details', 'text'],
        'allergies_yes' => ['Allergies (screen)', 'bool'],
        'allergies_text' => ['Allergies details', 'text'],

        // GP & consents
        'gp_selected' => ['GP selected', 'bool'],
        'gp_name' => ['GP name', 'text'],
        'gp_address' => ['GP address', 'text'],
        'gp_ods_code' => ['GP ODS code', 'text'],
        'gp_email' => ['GP email', 'text'],
        'gp_email_submitted' => ['GP email submitted', 'bool'],
        'ack_needles_swabs_bin' => ['Acknowledged needles, swabs & bin', 'bool'],
        'ack_first_attempt_delivery' => ['Acknowledged first-attempt delivery', 'bool'],
        'consent_scr_access' => ['Consent SCR access', 'bool'],
        'ack_treatment_rules' => ['Acknowledged treatment rules', 'bool'],
        'final_declaration' => ['Final declaration', 'bool'],
    ];

    // Groups
    $groups = [
        'About you' => [
            'age_18_to_85','ethnicity','pregnant_or_breastfeeding_or_planning','preg_text',
            'height_input','weight_input','bmi','target_weight',
        ],
        'Conditions & medicines' => [
            'weight_related_conditions','smoke','want_stop_smoking_info','drink_alcohol','want_alcohol_info',
            'prior_weightloss_or_led','prior_weightloss_details','require_evidence_yes',
            'eating_disorder','eating_disorder_text','has_conditions_yes','has_conditions','conditions_text',
            'has_medicines_yes','has_medicines_list','meds_text','oral_contraceptives','ocp_details',
            'exercise_4_5_per_week','exercise_text','daily_calories',
        ],
        'Past medical history' => [
            'kidney_or_liver_impairment','kidney_or_liver_impairment_text',
            'other_medical_conditions_yes','other_medical_conditions_text',
            'current_or_recent_meds_yes','current_or_recent_meds_text',
            'allergies_yes','allergies_text',
            'gp_selected','gp_name','gp_address','gp_ods_code','gp_email','gp_email_submitted',
        ],
        'Declarations' => [
            'ack_needles_swabs_bin','ack_first_attempt_delivery','consent_scr_access',
            'ack_treatment_rules','final_declaration',
        ],
    ];

    // Determine the session id safely for the form action
    $__sid = $sessionLike?->id ?? ($session->id ?? null);
@endphp

@if(!$form)
    <x-filament::section class="mb-4">
        <x-slot name="heading">Risk Assessment form not found</x-slot>
        <div>Please create an active RAF ClinicForm for service "{{ $serviceFor }}" and treatment "{{ $treatFor }}" to enable saving.</div>
    </x-filament::section>
@endif

@if($__sid)
<form id="cf_risk-assessment" method="POST"
      action="{{ $form ? url('/admin/consultations/' . $__sid . '/forms/' . $form->id . '/save') . '?tab=risk-assessment' : '#' }}">
    @csrf
    @if($form)
        <input type="hidden" name="__step_slug" value="raf">
    @endif

    <style>
      .pe-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
      @media (max-width: 980px) { .pe-grid { grid-template-columns: 1fr; } }
      .pe-heading { grid-column: 1 / -1; margin: 14px 0 4px; font-weight: 600; color: inherit; }
      .pe-label { display: block; margin: 0 0 6px; font-size: 14px; color: #cbd5e1; }
      .pe-label:not(:first-of-type) { border-top: 1px solid rgba(107,114,128,.35); padding-top: 12px; }
      .pe-field { display: block; }
      .pe-inline { display: inline-flex; align-items: center; gap: 8px; }
      .pe-input, .pe-text, .pe-number {
          width: 100%;
          padding: 10px 12px;
          border: 2px solid #6b7280;
          border-radius: 8px;
          background: transparent !important;
          color: inherit !important;
      }
      .pe-input:hover, .pe-text:hover, .pe-number:hover { border-color: #f59e0b; }
      .pe-input:focus, .pe-text:focus, .pe-number:focus { border-color: #fbbf24; box-shadow: 0 0 0 3px rgba(251,191,36,.25); outline: none; }
      .raf-card { border-radius: 12px; border: 1px solid rgba(107,114,128,.35); padding: 20px; }
      .raf-card p { margin: .4rem 0 .9rem; line-height: 1.6; }
      .pe-input::placeholder, .pe-text::placeholder { color: inherit; opacity: 0.6; }
      .pe-number { -moz-appearance: textfield; }
      .pe-number::-webkit-outer-spin-button, .pe-number::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

      /* Neutralize dark backgrounds coming from Filament containers */
      .fi-section, .fi-card, .fi-modal-content, .fi-page, .fi-body, .fi-main, .fi-tabs, .fi-panel, .fi-content {
        background: transparent !important;
        box-shadow: none !important;
      }

      /* Inputs and selects */
      :where(input, textarea, select) {
        background: transparent !important;
        color: inherit !important;
      }

      /* Remove any inner page heading that might get injected in content slots */
      #raf-content h1, #raf-content h2 { display: none; }
    </style>

    <div id="raf-content" class="raf-card"><div class="pe-grid">
    @foreach ($groups as $heading => $keys)
        <h4 class="pe-heading">{{ $heading }}</h4>
        @foreach ($keys as $key)
            @php
                if (!isset($fields[$key])) continue;
                [$label, $type] = $fields[$key];
                $current = $get($key);
                // Show fields even if empty so user can edit/add answers
            @endphp

            <div class="pe-field">
                <span class="pe-label">{{ $label }}</span>

                @if ($type === 'bool')
                    <input type="hidden" name="answers[{{ $key }}]" value="0">
                    <label class="pe-inline">
                        <input type="checkbox" value="1" name="answers[{{ $key }}]"
                               {{ ($current === true || $current === '1' || $current === 1 || $current === 'true') ? 'checked' : '' }}>
                        <span>Yes</span>
                    </label>
                @elseif ($type === 'number')
                    <input class="pe-number" type="number" step="any"
                           name="answers[{{ $key }}]" value="{{ is_numeric($current) ? $current : '' }}">
                @elseif ($type === 'tags')
                    <input class="pe-input" type="text"
                           name="answers[{{ $key }}]"
                           value="{{ $csv($current) }}"
                           placeholder="Comma separated">
                @else
                    <input class="pe-text" type="text"
                           name="answers[{{ $key }}]"
                           value="{{ is_scalar($current) ? $current : '' }}">
                @endif
            </div>
        @endforeach
    @endforeach
    </div>
    </div>

    @php

        // Helper: resolve a displayable image source from either a URL or a stored path
        $resolveImg = function ($urlKey, $pathKey) use ($answers) {
            $url  = data_get($answers, $urlKey);
            $path = data_get($answers, $pathKey);
            $src  = $url ?: $path;
            if ($src && is_string($src) && !str_starts_with($src, 'data:image') && !preg_match('~^https?://~i', $src)) {
                try { $src = \Illuminate\Support\Facades\Storage::url($src); } catch (\Throwable $e) {}
            }
            return $src;
        };

        // Common RAF intake keys
        $imageBlocks = [
            ['label' => 'Scale photo',   'url' => $resolveImg('scale_image_url', 'scale_image_path')],
            ['label' => 'Body photo',    'url' => $resolveImg('body_image_url',  'body_image_path')],
            ['label' => 'Evidence file', 'url' => $resolveImg('evidence_image_url', 'evidence_image_path')],
        ];

        // Keep only items that have a URL
        $imageBlocks = array_values(array_filter($imageBlocks, fn ($it) => !empty($it['url'])));
    @endphp

    @if (!empty($imageBlocks))
        <h4 class="pe-heading" style="margin-top:18px;">Uploaded images</h4>
        <div class="pe-images" style="display:flex;gap:16px;flex-wrap:wrap;">
            @foreach ($imageBlocks as $it)
                <div class="pe-img-card" style="border:1px solid rgba(148,163,184,0.25);border-radius:8px;padding:8px;">
                    <div class="pe-img-label" style="font-size:12px;color:#cbd5e1;margin-bottom:6px;">{{ $it['label'] }}</div>
                    <a href="{{ $it['url'] }}" target="_blank" rel="noreferrer">
                        <img class="pe-img" src="{{ $it['url'] }}" alt="{{ $it['label'] }}" style="max-width:220px;max-height:220px;display:block;border-radius:6px;">
                    </a>
                </div>
            @endforeach
        </div>
    @endif

</form>
@else
    <x-filament::section class="mt-4">
        <x-slot name="heading">Session required</x-slot>
        <div>Unable to determine consultation session id.</div>
    </x-filament::section>
@endif
