{{-- resources/views/consultations/pharmacist-advoce.blade.php --}}
{{-- Pharmacist Advice page that picks the correct Advice ClinicForm per service and treatment slug, like Record of Supply item prefill --}}

@php
    $sessionLike = $session ?? null;

    // Safe slug
    $slugify = function ($v) {
        if ($v === true) return 'true';
        if ($v === false) return 'false';
        $s = is_scalar($v) ? (string) $v : '';
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    };

    // Try to infer treatment slug from many places and capture candidates for debug
    $treatDebug = [];
    $inferTreatment = function ($sessionLike) use ($slugify, &$treatDebug) {
        $treatDebug = [];
        try {
            $toArr = function ($v) { if (is_array($v)) return $v; if (is_string($v)) { $d = json_decode($v, true); return is_array($d)?$d:[]; } if ($v instanceof \stdClass) return json_decode(json_encode($v), true) ?: []; return (array) $v; };

            // 1. Direct on session
            $cands = [
                $sessionLike->treatment_slug ?? null,
                $sessionLike->treatment ?? null,
            ];

            // 2. Attached order models
            $order = null;
            try {
                if (isset($sessionLike->order)) $order = $sessionLike->order;
                if (!$order && isset($sessionLike->order_id) && class_exists('App\\Models\\ApprovedOrder')) {
                    $order = \App\Models\ApprovedOrder::find($sessionLike->order_id);
                }
                if (!$order && isset($sessionLike->order_id) && class_exists('App\\Models\\Order')) {
                    $order = \App\Models\Order::find($sessionLike->order_id);
                }
            } catch (\Throwable $e) {}

            // 3. Order meta hints
            $meta = $toArr($order?->meta ?? []);
            $cands[] = data_get($meta, 'treatment_slug');
            $cands[] = data_get($meta, 'consultation.treatment_slug');
            $cands[] = data_get($meta, 'service.treatment_slug');

            // 4. Common product line shapes
            $lines = $toArr(data_get($meta, 'lines') ?? data_get($meta, 'items') ?? data_get($meta, 'order.items') ?? []);
            if (empty($lines) && $order && method_exists($order, 'items')) {
                try { $lines = $toArr($order->items ?? []); } catch (\Throwable $e) {}
            }
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $cands[] = data_get($ln, 'treatment_slug');
                    $cands[] = data_get($ln, 'slug');
                    $cands[] = data_get($ln, 'product.slug');
                    $cands[] = data_get($ln, 'product_slug');
                    $sku = data_get($ln, 'sku');
                    if (is_string($sku) && $sku !== '') {
                        $parts = preg_split('/[^a-z0-9]+/i', strtolower($sku));
                        $cands[] = $parts[0] ?? null;
                    }
                }
            }

            // 5. Consultation forms hydration
            $forms = $toArr(data_get($meta, 'forms') ?? data_get($sessionLike, 'meta.forms') ?? []);
            foreach (['reorder','assessment','raf','advice','pharmacist-advice'] as $k) {
                $cands[] = data_get($forms, "$k.treatment_slug");
            }

            // Normalise and save for debug
            $cands = array_values(array_filter(array_map(function($v){ return is_string($v) ? trim($v) : $v; }, $cands)));
            $treatDebug = array_values(array_unique(array_filter(array_map($slugify, $cands))));

            foreach ($treatDebug as $slug) {
                if ($slug !== '') return $slug;
            }
        } catch (\Throwable $e) {}
        return null;
    };

    // Resolve service and treatment for matching
    $serviceFor = $slugify($serviceSlugForForm ?? ($sessionLike->service_slug ?? ($sessionLike->service ?? null)));
    $treatFor   = $slugify($treatmentSlugForForm ?? ($sessionLike->treatment_slug ?? ($sessionLike->treatment ?? null)));
    if (! $treatFor) {
        $treatFor = $inferTreatment($sessionLike);
    }
    $__adviceLookupTried = [];

    // Prefer template that StartConsultation placed on the session
    $form = $form ?? null;
    if (! $form && isset($sessionLike->templates)) {
        $tpl = \Illuminate\Support\Arr::get($sessionLike->templates, 'advice')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'pharmacist_advice');
        if ($tpl) {
            if (is_array($tpl)) {
                $fid = $tpl['id'] ?? $tpl['form_id'] ?? null;
                if ($fid) { $form = \App\Models\ClinicForm::find($fid); if ($form) $__adviceLookupTried[] = 'templates.advice id=' . $form->id; }
            } elseif (is_object($tpl) && ($tpl instanceof \App\Models\ClinicForm)) {
                $form = $tpl; if ($form) $__adviceLookupTried[] = 'templates.advice model id=' . $form->id;
            } elseif (is_numeric($tpl)) {
                $form = \App\Models\ClinicForm::find((int) $tpl); if ($form) $__adviceLookupTried[] = 'templates.advice numeric id=' . $form->id;
            }
        }
    }

    // If a more specific Advice form exists for this service+treatment, prefer it over a generic template
    try {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'advice')
            ->where('is_active', 1)
            ->orderByDesc('version')->orderByDesc('id');

        $candidate = null;
        if ($serviceFor && $treatFor) {
            $candidate = $base()->where('service_slug', $serviceFor)
                                ->where('treatment_slug', $treatFor)
                                ->first();
            if ($candidate) { $__adviceLookupTried[] = 'override match service+treatment id=' . $candidate->id; }
        }

        if (! $candidate && $serviceFor) {
            $candidate = $base()->where('service_slug', $serviceFor)
                                ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                                ->first();
            if ($candidate) { $__adviceLookupTried[] = 'override match service only id=' . $candidate->id; }
        }

        if (! $candidate) {
            $svc = \App\Models\Service::query()->where('slug', $serviceFor)->first();
            if ($svc && $svc->adviceForm) {
                $candidate = $svc->adviceForm;
                if ($candidate) { $__adviceLookupTried[] = 'override service.adviceForm id=' . $candidate->id; }
            }
        }

        if ($candidate && (!isset($form) || ($candidate->id !== ($form->id ?? null)))) {
            $form = $candidate;
            $__adviceLookupTried[] = 'using candidate override';
        }
    } catch (\Throwable $e) {
        // no-op
    }

    // Fallbacks by service and treatment using form_type advice
    if (! $form) {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'advice')
            ->where('is_active', 1)
            ->orderByDesc('version')->orderByDesc('id');

        if ($serviceFor && $treatFor) {
            $form = $base()->where('service_slug', $serviceFor)
                           ->where('treatment_slug', $treatFor)->first();
            if ($form) $__adviceLookupTried[] = "match service+treatment";
        }
        if (! $form && $serviceFor) {
            $form = $base()->where('service_slug', $serviceFor)
                           ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                           ->first();
            if ($form) $__adviceLookupTried[] = "match service only";
        }
        if (! $form && $serviceFor) {
            $svc = \App\Models\Service::query()->where('slug', $serviceFor)->first();
            if ($svc && $svc->adviceForm) { $form = $svc->adviceForm; if ($form) $__adviceLookupTried[] = "service.adviceForm"; }
        }
        if (! $form) {
            $form = $base()->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
                           ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                           ->first();
            if ($form) $__adviceLookupTried[] = "global fallback";
        }
    }

    // Admin notes resolver pulled from Record of Supply logic
    $adminNotes = '';
    $order = $order ?? null;
    try {
        // Prefer lazy-loaded relation if present on the session
        if (!$order && isset($sessionLike) && method_exists($sessionLike, 'order')) {
            $order = $sessionLike->order;
        }
        // Fallback to known order models by order_id
        if (!$order && isset($sessionLike->order_id) && class_exists('App\\Models\\ApprovedOrder')) {
            $order = \App\Models\ApprovedOrder::find($sessionLike->order_id);
        }
        if (!$order && isset($sessionLike->order_id) && class_exists('App\\Models\\Order')) {
            $order = \App\Models\Order::find($sessionLike->order_id);
        }

        if ($order) {
            // 1) Direct column if available
            if (isset($order->admin_notes) && is_string($order->admin_notes) && trim($order->admin_notes) !== '') {
                $adminNotes = (string) $order->admin_notes;
            }
            // 2) Meta lookups for common shapes
            $metaArr = is_array($order->meta ?? null) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
            $paths = [
                'admin_notes',
                'notes.admin',
                'admin.notes',
                'internal.admin_notes',
                'internal_notes',
                'internal.admin.notes',
                'order.admin_notes',
                'manager_notes',
                'notes',
            ];
            foreach ($paths as $p) {
                $v = data_get($metaArr, $p);
                if (is_string($v) && trim($v) !== '') { $adminNotes = (string) $v; break; }
            }
        }
    } catch (\Throwable $e) {
        // ignore – admin notes are optional
    }

    // Decode schema
    $schema = [];
    if ($form) {
        $raw = $form->schema ?? [];
        $schema = is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []);
    }

    // Normalise schema to sections with fields
    $sections = [];
    if (is_array($schema) && !empty($schema)) {
        if (array_key_exists('fields', $schema[0] ?? [])) {
            $sections = $schema;
        } else {
            $current = ['title' => null, 'summary' => null, 'fields' => []];
            foreach ($schema as $blk) {
                $type = $blk['type'] ?? null;
                $data = (array) ($blk['data'] ?? []);
                if ($type === 'section') {
                    if (!empty($current['fields'])) $sections[] = $current;
                    $current = [
                        'title'   => $data['label'] ?? ($data['title'] ?? 'Section'),
                        'summary' => $data['summary'] ?? ($data['description'] ?? null),
                        'fields'  => [],
                    ];
                } else {
                    $field = ['type' => $type];
                    foreach (['label','key','placeholder','help','description','required','options','content','accept','multiple','showIf','hidden','disabled'] as $k) {
                        if (array_key_exists($k, $data)) $field[$k] = $data[$k];
                    }
                    $current['fields'][] = $field;
                }
            }
            if (!empty($current['fields'])) $sections[] = $current;
        }
    }

    // Answers loader to prefill
    $stepSlug = 'pharmacist-advice';
    $loadAnswers = function ($sessionLike, $form, $stepSlug) {
        $toArr = function ($v) { if (is_array($v)) return $v; if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; } if ($v instanceof \stdClass) return json_decode(json_encode($v), true) ?: []; return (array) $v; };
        $slug  = fn($s) => \Illuminate\Support\Str::slug((string)$s);
        $aliases = array_values(array_unique([
            (string)$stepSlug,
            str_replace('_','-',(string)$stepSlug),
            str_replace('-','_',(string)$stepSlug),
            $form->form_type ?? null,
            $slug($form->form_type ?? ''),
            'advice',
            'pharmacist-advice',
        ]));

        $answers = [];
        try {
            $q = \App\Models\ConsultationFormResponse::query()
                ->where('consultation_session_id', $sessionLike->id ?? null)
                ->latest('id');

            if ($form && $form->id) {
                $resp = (clone $q)->where('clinic_form_id', $form->id)->first();
                if ($resp) {
                    $raw = $resp->data;
                    $answers = is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []);
                    $answers = (array) ($answers['answers'] ?? $answers['data'] ?? $answers);
                }
            }

            if (empty($answers)) {
                $q2 = \App\Models\ConsultationFormResponse::query()
                    ->where('consultation_session_id', $sessionLike->id ?? null)
                    ->where(function ($qq) use ($aliases) {
                        $qq->whereIn('form_type', $aliases);
                        foreach ($aliases as $a) {
                            $qq->orWhere('step_slug', 'like', "%{$a}%")
                               ->orWhere('title', 'like', "%{$a}%");
                        }
                    })
                    ->latest('id')
                    ->first();

                if ($q2) {
                    $raw = $q2->data;
                    $answers = is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []);
                    $answers = (array) ($answers['answers'] ?? $answers['data'] ?? $answers['assessment']['answers'] ?? $answers);
                }
            }
        } catch (\Throwable $e) {}

        // Session meta fallbacks
        if (empty($answers) && isset($sessionLike->meta)) {
            $meta = $toArr($sessionLike->meta ?? []);
            foreach ($aliases as $a) {
                foreach (["forms.$a.answers","forms.$a.data","forms_qa.$a","formsQA.$a","$a.answers","$a.data"] as $p) {
                    $v = data_get($meta, $p);
                    if (is_array($v) && !empty($v)) { $answers = $v; break 2; }
                }
            }
            if (isset($answers['answers']) && is_array($answers['answers'])) $answers = $answers['answers'];
        }

        // Normalise list-of-rows -> map
        if (is_array($answers) && array_keys($answers) === range(0, count($answers)-1)) {
            $map = [];
            foreach ($answers as $row) {
                if (!is_array($row)) continue;
                $k = $row['key'] ?? $row['name'] ?? $row['question'] ?? $row['label'] ?? null;
                $v = $row['value'] ?? $row['answer'] ?? $row['raw'] ?? ($row['selected']['value'] ?? null);
                if ($k !== null) $map[(string)$k] = $v;
            }
            if (!empty($map)) $answers = $map;
        }

        // Flatten nested
        $flat = [];
        foreach ((array)$answers as $k => $v) {
            if (is_array($v) && \Illuminate\Support\Arr::isAssoc($v)) {
                $flat[$k] = $v['raw'] ?? $v['answer'] ?? $v['value'] ?? $v;
                foreach ($v as $ik => $iv) if (!isset($flat[$ik])) $flat[$ik] = $iv;
            } else {
                $flat[$k] = $v;
            }
        }
        return $flat;
    };

    $oldData = $loadAnswers($sessionLike ?? $session, $form ?? null, $stepSlug);

    // Consultation notes help text (do not prefill textarea)
    $consultationNotesHelp = "Use a structured approach for example SOAP or encounter based\n\nS Subjective presenting complaint history medicines allergies\nO Objective observations exam findings investigations\nA Assessment working diagnosis differentials risk stratification\nP Plan treatment prescriptions referrals safety netting follow up\n\nImportant safety information\n\nPancreatitis (inflammation of the pancreas) is a possible side effect with GLP-1 receptor agonists and dual GLP-1/GIP receptor agonists. In rare reports this can have serious or fatal outcomes.\n\nSeek urgent medical attention if you experience severe, persistent abdominal pain that may radiate to your back and may be accompanied by nausea and vomiting, as this may be a sign of pancreatitis.\n\nDo not restart GLP-1 receptor agonist or GLP-1/GIP receptor agonist treatment if pancreatitis is confirmed.\n\nReport suspected side effects through the Yellow Card scheme.";

    // Prefer saved notes only (leave blank if none)
    $consultationNotesValue = old('consultation_notes', $oldData['consultation_notes'] ?? '');

    // Options helper
    $normaliseOptions = function ($raw) {
        $out = [];
        foreach ((array) $raw as $idx => $opt) {
            if (is_array($opt)) {
                $label = (string) ($opt['label'] ?? ($opt['value'] ?? $idx));
                $value = (string) ($opt['value'] ?? \Illuminate\Support\Str::slug($label));
            } else {
                $label = is_string($opt) ? $opt : (string) $idx;
                $value = is_string($opt) ? \Illuminate\Support\Str::slug($opt) : (string) $idx;
            }
            $out[] = ['label' => $label, 'value' => $value];
        }
        return $out;
    };
@endphp

@if (request()->boolean('debug'))
    <div style="margin:16px 0;padding:10px 12px;border:1px dashed rgba(255,255,255,.35);border-radius:10px;font-size:12px">
        <div>debug step pharmacist-advice</div>
        <div>service {{ $serviceFor ?? 'n/a' }} treatment {{ $treatFor ?? 'n/a' }}</div>
        <div>treatment candidates {{ implode(', ', $treatDebug ?? []) }}</div>
        @if ($form)
            <div>form id {{ $form->id }} type {{ $form->form_type ?? 'n/a' }} name {{ $form->name ?? 'n/a' }}</div>
            <div>form service {{ $form->service_slug ?? 'n/a' }} treatment {{ $form->treatment_slug ?? 'n/a' }} version {{ $form->version ?? 'n/a' }}</div>
            <div>schema sections {{ count($sections ?? []) }}</div>
            <div>lookup path {{ implode(' -> ', $__adviceLookupTried ?? []) }}</div>
        @else
            <div>form not found</div>
            <div>lookup path {{ implode(' -> ', $__adviceLookupTried ?? []) }}</div>
        @endif
    </div>
@endif

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
      .cf-field-flat{border:0;padding:0}
      .cf-title{font-weight:600;font-size:16px;color:#e5e7eb;margin:0 0 6px 0}
      .cf-summary{font-size:13px;color:#9ca3af;margin:0}
      .cf-label{font-size:14px;color:#e5e7eb;display:block;margin-bottom:6px}
      .cf-help{font-size:12px;color:#9ca3af;margin-top:6px}
      .cf-checkbox-row{display:flex;align-items:center;gap:10px}
      .cf-ul{list-style:disc;padding-left:20px;margin:0}
      .cf-ul li{margin:4px 0;color:#e5e7eb}
      .cf-paras p{margin:8px 0;line-height:1.6;color:#e5e7eb}
      @media(min-width:768px){.cf-section-card{padding:28px}.cf-grid{gap:20px}.cf-field-card{padding:20px}}
      .cf-input, .cf-select, .cf-file{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px}
      .cf-textarea{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px;min-height:140px;resize:vertical}
      .cf-input:focus, .cf-textarea:focus, .cf-select:focus{outline:none;border-color:rgba(255,255,255,.28);box-shadow:0 0 0 2px rgba(255,255,255,.12)}
      /* Voice toolbar styling */
      .voice-toolbar{display:flex;align-items:center;gap:10px;margin-top:8px}
      .voice-btn{appearance:none;border:0;border-radius:999px;padding:8px 14px;font-weight:600;cursor:pointer;background:rgba(34,197,94,.15);transition:filter .15s ease, background-color .15s ease}
      .voice-btn:hover{filter:brightness(1.08)}
      .voice-btn[aria-pressed="true"]{background:rgba(239,68,68,.18)}
      .voice-status{font-size:12px;opacity:.85;display:inline-flex;align-items:center;gap:6px}
      .voice-dot{width:8px;height:8px;border-radius:999px;background:#9ca3af;display:inline-block}
      .voice-btn[aria-pressed="true"] + .voice-status .voice-dot{background:#22c55e;animation:voicepulse 1.2s infinite}
      @keyframes voicepulse{0%{transform:scale(1);opacity:.6}50%{transform:scale(1.35);opacity:1}100%{transform:scale(1);opacity:.6}}
      /* Pretty checkbox (no :has dependency) */
      .cf-check-row{display:flex;align-items:flex-start;gap:10px}
      .cf-check-wrap{display:inline-flex;align-items:flex-start;gap:10px;cursor:pointer;user-select:none}
      .cf-check-input{position:absolute;opacity:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      .cf-check-box{width:20px;height:20px;border-radius:6px;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.03);display:inline-flex;align-items:center;justify-content:center;transition:background .15s ease,border-color .15s ease,box-shadow .15s ease;flex:0 0 auto;margin-top:2px}
      .cf-check-box::after{content:'';width:10px;height:6px;border-left:2px solid transparent;border-bottom:2px solid transparent;transform:rotate(-45deg);margin-top:-1px;opacity:0;transition:opacity .15s ease,border-color .15s ease}
      .cf-check-label{font-size:14px;color:#e5e7eb;line-height:1.5}
      .cf-check-help{font-size:12px;color:#9ca3af;margin-top:6px}
      .cf-check-input:focus-visible + .cf-check-box{outline:2px solid rgba(34,197,94,.6);outline-offset:2px}
      .cf-check-input:checked + .cf-check-box{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);box-shadow:0 0 0 2px rgba(34,197,94,.10)}
      .cf-check-input:checked + .cf-check-box::after{border-color:rgba(34,197,94,1);opacity:1}
    </style>
@endonce

@if (! $form)
    <div class="rounded-md border border-red-300 bg-red-50 text-red-800 p-4">
        <div class="font-semibold">Advice form not found</div>
        <div class="mt-1 text-sm">
            Please create an active Consultation Advice ClinicForm for
            <code>{{ $serviceFor ?? 'any service' }}</code>
            {{ $treatFor ? 'and treatment ' . $treatFor : '' }}
            or assign an Advice form to this service.
        </div>
    </div>
@else
    <form id="cf_pharmacist-advice" method="POST" enctype="multipart/form-data" action="{{ route('consultations.forms.save', ['session' => $sessionLike->id ?? $session->id ?? null, 'form' => $form->id]) }}?tab=pharmacist-advice">
        @csrf
        <input type="hidden" name="__step_slug" value="pharmacist-advice">
        <input type="hidden" id="__go_next" name="__go_next" value="0">
        <input type="hidden" name="form_type" value="advice">
        <input type="hidden" name="service" value="{{ $serviceFor ?? '' }}">
        <input type="hidden" name="treatment" value="{{ $treatFor ?? '' }}">

        <div class="space-y-10">
            @foreach ($sections as $section)
                @php
                    $title = $section['title'] ?? null;
                    $summary = $section['summary'] ?? null;
                @endphp
                <div class="cf-section-card">
                    @if ($title)
                        <div class="mb-4">
                            <h3 class="cf-title">{{ $title }}</h3>
                            @if ($summary)
                                <p class="cf-summary">{!! nl2br(e($summary)) !!}</p>
                            @endif
                        </div>
                    @endif

                    <div class="cf-grid">
                    @php $fieldCard = 'cf-field-card'; @endphp
                    @foreach (($section['fields'] ?? []) as $i => $field)
                        @php
                            $type  = $field['type'] ?? 'text_input';
                            $label = $field['label'] ?? null;
                            $key   = $field['key'] ?? ($label ? \Illuminate\Support\Str::slug($label) : ('field_'.$loop->index));
                            $name  = $key;
                            $help  = $field['help'] ?? ($field['description'] ?? null);
                            $req   = (bool) ($field['required'] ?? false);
                            $ph    = $field['placeholder'] ?? null;
                            $isMultiple = (bool) ($field['multiple'] ?? false);

                            if ($type === 'select' && $isMultiple) {
                                $val = old($name, (array) ($oldData[$name] ?? []));
                            } elseif ($type === 'checkbox') {
                                $val = old($name, array_key_exists($name, (array) $oldData) ? $oldData[$name] : 1);
                            } else {
                                $val = old($name, $oldData[$name] ?? '');
                            }

                            $showIf = $field['showIf'] ?? ($field['show_if'] ?? null);
                            $isHidden = (bool) ($field['hidden'] ?? false);
                            $isDisabled = (bool) ($field['disabled'] ?? false);

                            $cond = null;
                            if (is_array($showIf)) {
                                $cond = [
                                    'field'  => $showIf['field'] ?? null,
                                    'equals' => $showIf['equals'] ?? null,
                                    'in'     => $showIf['in'] ?? null,
                                    'not'    => $showIf['notEquals'] ?? ($showIf['not'] ?? null),
                                    'truthy' => !empty($showIf['truthy']) ? true : null,
                                ];
                                $cond = array_filter($cond, fn($v) => !is_null($v) && $v !== '');
                                if (empty($cond['field'] ?? null)) { $cond = null; }
                            }

                            $wrapperAttr = '';
                            if ($cond) {
                                $wrapperAttr .= ' data-showif=' . "'" . e(json_encode($cond)) . "'";
                            }
                            if ($isHidden) {
                                $wrapperAttr .= ' style="display:none" aria-hidden="true"';
                            }
                            $disabledAttr = $isDisabled ? 'disabled' : '';
                        @endphp

                        {{-- Static rich text blocks --}}
                        @if ($type === 'text_block')
                            @php
                                $html  = (string) ($field['content'] ?? '');
                                $align = (string) ($field['align'] ?? 'left');
                                $hasHtml = (bool) preg_match('/<\w+[^>]*>/', $html ?? '');
                            @endphp
                            @if (trim(strip_tags($html)) !== '')
                                <div class="{{ $fieldCard }}" style="text-align: {{ $align }};">
                                    @if ($hasHtml)
                                        {!! $html !!}
                                    @else
                                        @php
                                            $lines = preg_split("/\r\n|\r|\n/", $html);
                                            $clean = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
                                            $bulletCount = 0;
                                            foreach ($clean as $l) {
                                                if (preg_match('/^(?:•|\d+\)|\d+\.|-)\s*/', $l)) $bulletCount++;
                                            }
                                            $asList = $bulletCount >= max(3, (int) floor(count($clean) * 0.6));
                                        @endphp

                                        @if ($asList)
                                            <ul class="cf-ul">
                                                @foreach ($clean as $ln)
                                                    @php $txt = preg_replace('/^(?:•|\d+\)|\d+\.|-)+\s*/', '', $ln); @endphp
                                                    <li>{{ $txt }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <div class="cf-paras">
                                                @foreach ($clean as $ln)
                                                    <p>{{ $ln }}</p>
                                                @endforeach
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @endif
                            @continue
                        @endif

                        @if ($type === 'radio')
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label class="cf-label">{{ $label }}</label>
                                @endif
                                <div class="flex flex-wrap items-center -m-2">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $idx => $op)
                                        @php $rid = $name.'_'.$idx; @endphp
                                        <label for="{{ $rid }}" class="p-2 inline-flex items-center gap-2 text-sm text-gray-200">
                                            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $op['value'] }}" class="rounded-full bg-gray-800/70 border-gray-700 focus:ring-2" {{ (string)$val === (string)$op['value'] ? 'checked' : '' }} {!! $disabledAttr !!}>
                                            <span>{{ $op['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'textarea')
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="6" placeholder="{{ $ph }}" @if($req) required @endif class="cf-textarea" {!! $disabledAttr !!}>{{ $val }}</textarea>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'select')
                            @php $isMultiple = (bool) ($field['multiple'] ?? false); @endphp
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                @php
                                    $vals = $val;
                                    if ($isMultiple) {
                                        $vals = is_array($vals) ? $vals : ($vals!=='' ? array_map('trim', explode(',', (string)$vals)) : []);
                                    }
                                @endphp
                                <select id="{{ $name }}" name="{{ $name }}{{ $isMultiple ? '[]' : '' }}" @if($isMultiple) multiple @endif @if($req) required @endif class="cf-input" {!! $disabledAttr !!}>
                                    @foreach($normaliseOptions($field['options'] ?? []) as $op)
                                        @if($isMultiple)
                                            <option value="{{ $op['value'] }}" {{ in_array((string)$op['value'], array_map('strval', (array) $vals), true) ? 'selected' : '' }}>{{ $op['label'] }}</option>
                                        @else
                                            <option value="{{ $op['value'] }}" {{ (string)$val === (string)$op['value'] ? 'selected' : '' }}>{{ $op['label'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'checkbox')
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @php
                                    $isChecked = ($val === 1 || $val === true || (is_string($val) && in_array(strtolower($val), ['1','on','yes','true','checked','done'], true)));
                                @endphp
                                <div class="cf-check-row">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <label for="{{ $name }}" class="cf-check-wrap">
                                        <input type="checkbox" id="{{ $name }}" name="{{ $name }}" value="1" class="cf-check-input" {{ $isChecked ? 'checked' : '' }} {!! $disabledAttr !!}>
                                        <span class="cf-check-box" aria-hidden="true"></span>
                                        <span class="cf-check-label">{{ $label }}</span>
                                    </label>
                                </div>
                                @if($help)<p class="cf-check-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'date')
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="date" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" @if($req) required @endif class="cf-input" {!! $disabledAttr !!} />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'file' || $type === 'file_upload')
                            @php
                                $accept   = $field['accept'] ?? null;
                                $multiple = (bool) ($field['multiple'] ?? false);
                            @endphp
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="file" id="{{ $name }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="cf-file" {!! $disabledAttr !!} />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @else
                            <div class="{{ $fieldCard }}" {!! $wrapperAttr !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" placeholder="{{ $ph }}" @if($req) required @endif class="cf-input" {!! $disabledAttr !!} />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @endif
                    @endforeach
                </div>
                </div>
            @endforeach
            

            {{-- Admin Notes (from Record of Supply logic) --}}
            <div class="cf-section-card">
                <div class="cf-field-flat">
                    <label class="cf-label">Admin notes</label>
                    <textarea
                        id="admin_notes"
                        name="admin_notes"
                        rows="6"
                        class="cf-textarea"
                    >{{ old('admin_notes', $oldData['admin_notes'] ?? ($adminNotes ?? '')) }}</textarea>
                    <input type="hidden" name="answers[admin_notes]" id="answers_admin_notes" value="{{ old('admin_notes', $oldData['admin_notes'] ?? ($adminNotes ?? '')) }}">
                </div>
            </div>

            {{-- Consultation Notes --}}
            <div class="cf-section-card">
                <div class="cf-field-flat">
                    <label class="cf-label">Consultation notes</label>
                    <textarea
                        id="consultation_notes"
                        name="consultation_notes"
                        rows="6"
                        class="cf-textarea"
                    >{{ $consultationNotesValue }}</textarea>
                    <input type="hidden" name="answers[consultation_notes]" id="answers_consultation_notes" value="{{ $consultationNotesValue }}">
                    <p class="cf-help">{!! nl2br(e($consultationNotesHelp)) !!}</p>
                    <div class="voice-toolbar" id="voice_toolbar_consultation_notes">
                      <button type="button" class="voice-btn" id="voice_btn_consultation_notes" aria-pressed="false" aria-controls="consultation_notes">Start dictation</button>
                      <span id="voice_status_consultation_notes" class="voice-status"><i class="voice-dot"></i> Mic off</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="cf-section-card">
            <div class="cf-field-card">
                <div class="cf-check-row">
                    <label for="__cf_check_all" class="cf-check-wrap">
                        <input type="checkbox" id="__cf_check_all" class="cf-check-input">
                        <span class="cf-check-box" aria-hidden="true"></span>
                        <span class="cf-check-label">Select all checkboxes</span>
                    </label>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
          var form = document.getElementById('cf_pharmacist-advice');
          if (!form) return;
          var master = document.getElementById('__cf_check_all');
          if (!master) return;

          function boxes() {
            return form.querySelectorAll('input[type="checkbox"]:not(#__cf_check_all):not(:disabled)');
          }
          function setAll(state) {
            boxes().forEach(function(b){ b.checked = state; });
          }
          function syncMaster() {
            var list = boxes();
            if (!list.length) { master.checked = false; master.indeterminate = false; return; }
            var allChecked = true, anyChecked = false;
            list.forEach(function(b){ if (b.checked) anyChecked = true; else allChecked = false; });
            master.checked = allChecked;
            master.indeterminate = !allChecked && anyChecked;
          }

          master.addEventListener('change', function(){ setAll(master.checked); });
          form.addEventListener('change', function(e){
            var t = e.target;
            if (t && t.type === 'checkbox' && t.id !== '__cf_check_all') syncMaster();
          });

          syncMaster();
        });
        </script>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
          var form = document.getElementById('cf_pharmacist-advice');
          if (!form) return;
          function bindMirror(srcId, mirrorId){
            var src = document.getElementById(srcId);
            var mir = document.getElementById(mirrorId);
            if (!src || !mir) return;
            function sync(){ mir.value = src.value; }
            src.addEventListener('input', sync);
            form.addEventListener('submit', sync);
            sync();
          }
          bindMirror('admin_notes','answers_admin_notes');
          bindMirror('consultation_notes','answers_consultation_notes');
        });
        </script>

        <script>
        document.addEventListener('DOMContentLoaded', function(){
          var form = document.getElementById('cf_pharmacist-advice');
          if (!form) return;

          function findControlsByName(name){
            var list = [];
            form.querySelectorAll('[name="'+name+'"]').forEach(function(el){ list.push(el); });
            form.querySelectorAll('[name="'+name+'[]"]').forEach(function(el){ list.push(el); });
            return list;
          }

          function getValue(name){
            var els = findControlsByName(name);
            if (!els.length) return null;
            if (els[0].type === 'radio') {
              var c = els.find(function(e){ return e.checked; });
              return c ? c.value : null;
            }
            if (els[0].type === 'checkbox' && els.length === 1) {
              return els[0].checked ? '1' : '';
            }
            if (els[0].tagName === 'SELECT') {
              var sel = els[0];
              if (sel.multiple) {
                var out = [];
                [].forEach.call(sel.options, function(o){ if (o.selected) out.push(o.value); });
                return out;
              }
              return sel.value;
            }
            if (els[0].type === 'file') {
              return els[0].files && els[0].files.length ? '[file]' : '';
            }
            if (els.length === 1) return (els[0].value || '').trim();
            return els.map(function(e){ return (e.value || '').trim(); });
          }

          function matchesCond(val, cond){
            var arr = Array.isArray(val) ? val.map(String) : [val === null ? '' : String(val)];
            if (cond.equals !== undefined) {
              var eq = String(cond.equals);
              if (!arr.some(function(v){ return v === eq; })) return false;
            }
            if (cond.in && cond.in.length) {
              var set = cond.in.map(String);
              if (!arr.some(function(v){ return set.indexOf(v) !== -1; })) return false;
            }
            if (cond.not !== undefined) {
              var neq = String(cond.not);
              if (arr.some(function(v){ return v === neq; })) return false;
            }
            if (cond.truthy) {
              var tr = arr.some(function(v){ return v !== '' && v !== '0' && v.toLowerCase() !== 'false'; });
              if (!tr) return false;
            }
            return true;
          }

          function applyFor(el){
            var raw = el.getAttribute('data-showif');
            if (!raw) return;
            var cond; try { cond = JSON.parse(raw); } catch(e){ return; }
            if (!cond || !cond.field) return;
            var ok = matchesCond(getValue(cond.field), cond);
            el.style.display = ok ? '' : 'none';
            el.setAttribute('aria-hidden', ok ? 'false' : 'true');
            el.querySelectorAll('input,select,textarea').forEach(function(ctrl){ ctrl.disabled = !ok; });
          }

          var blocks = [].slice.call(form.querySelectorAll('[data-showif]'));
          var deps = {};
          blocks.forEach(function(el){
            try {
              var c = JSON.parse(el.getAttribute('data-showif') || '{}');
              if (!c || !c.field) return;
              (deps[c.field] = deps[c.field] || []).push(el);
            } catch(e){}
          });

          Object.keys(deps).forEach(function(name){
            var ctrls = findControlsByName(name);
            ctrls.forEach(function(ctrl){
              var ev = (ctrl.tagName === 'SELECT' || ctrl.type === 'radio' || ctrl.type === 'checkbox' || ctrl.type === 'file') ? 'change' : 'input';
              ctrl.addEventListener(ev, function(){ (deps[name] || []).forEach(applyFor); });
            });
          });

          blocks.forEach(applyFor);
        });
        </script>

    <script>
(function(){
  var btn = document.getElementById('voice_btn_consultation_notes');
  var statusEl = document.getElementById('voice_status_consultation_notes');
  var ta = document.getElementById('consultation_notes');
  var mirror = document.getElementById('answers_consultation_notes');
  if (!btn || !statusEl || !ta) return;

  // Feature detect SpeechRecognition
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) {
    // Hide toolbar if unsupported
    var tb = document.getElementById('voice_toolbar_consultation_notes');
    if (tb) tb.style.display = 'none';
    return;
  }

  var rec = new SR();
  rec.lang = 'en-GB';
  rec.continuous = true;
  rec.interimResults = true;

  var active = false;
  var userStopped = false; // distinguish from auto end

  function syncMirror(){ if (mirror) mirror.value = ta.value; }
  function setStatus(text){ statusEl.textContent = text; }
  function toggleUI(on){
    active = !!on;
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.textContent = on ? 'Stop dictation' : 'Start dictation';
    setStatus(on ? 'Listening…' : 'Mic off');
  }

  // Buffer interim text without permanently inserting until final
  var interim = '';

  rec.onresult = function(e){
    interim = '';
    var finalDelta = '';
    for (var i = e.resultIndex; i < e.results.length; i++) {
      var res = e.results[i];
      var txt = res[0].transcript;
      if (res.isFinal) {
        finalDelta += txt;
      } else {
        interim += txt;
      }
    }
    if (finalDelta) {
      // Append final text with a space if needed
      var needsSpace = ta.value && !/\s$/.test(ta.value);
      ta.value += (needsSpace ? ' ' : '') + finalDelta.trim();
      syncMirror();
      // Move caret to end
      try { ta.selectionStart = ta.selectionEnd = ta.value.length; } catch(e) {}
    }
    // Optionally show interim in status
    if (interim) setStatus('Listening… ' + interim.trim()); else setStatus('Listening…');
  };

  rec.onerror = function(e){
    setStatus('Mic error ' + (e.error || ''));
  };

  rec.onstart = function(){ toggleUI(true); };
  rec.onend = function(){
    toggleUI(false);
    if (!userStopped) {
      // Auto-restart to keep continuous capture on some browsers
      try { rec.start(); } catch(err) {}
    }
  };

  btn.addEventListener('click', function(){
    if (!active) {
      userStopped = false;
      try { rec.start(); } catch(e) { setStatus('Mic error starting'); }
    } else {
      userStopped = true;
      try { rec.stop(); } catch(e) {}
    }
  });

  // Keep mirror in sync when user types manually
  ta.addEventListener('input', syncMirror);
})();
</script>
    </form>
@endif