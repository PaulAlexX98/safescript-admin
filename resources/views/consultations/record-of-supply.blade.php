

{{-- resources/views/consultations/record-of-supply.blade.php --}}
{{-- Service-first Record of Supply page using the Clinical Notes form type. Matches the Risk Assessment and Pharmacist Advice layout. --}}

@php
    $sessionLike = $session ?? null;

    // Slug helpers
    $slugify = function ($v) {
        return $v ? \Illuminate\Support\Str::slug((string) $v) : null;
    };

    // Resolve service and treatment for matching
    $serviceFor = $slugify($serviceSlugForForm ?? ($sessionLike->service_slug ?? ($sessionLike->service ?? null)));
    $treatFor   = $slugify($treatmentSlugForForm ?? ($sessionLike->treatment_slug ?? ($sessionLike->treatment ?? null)));

    // Prefer template that StartConsultation placed on the session
    $form = $form ?? null;
    if (! $form && isset($sessionLike->templates)) {
        $tpl = \Illuminate\Support\Arr::get($sessionLike->templates, 'clinical_notes')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'record_of_supply')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'supply')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'clinicalNotes');
        if ($tpl) {
            if (is_array($tpl)) {
                $fid = $tpl['id'] ?? $tpl['form_id'] ?? null;
                if ($fid) { $form = \App\Models\ClinicForm::find($fid); }
            } elseif (is_object($tpl) && ($tpl instanceof \App\Models\ClinicForm)) {
                $form = $tpl;
            } elseif (is_numeric($tpl)) {
                $form = \App\Models\ClinicForm::find((int) $tpl);
            }
        }
    }

    // Fallbacks by service and treatment using form_type 'clinical_notes'
    if (! $form) {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'clinical_notes')
            ->where('is_active', 1)
            ->orderByDesc('version')->orderByDesc('id');

        if ($serviceFor && $treatFor) {
            $form = $base()->where('service_slug', $serviceFor)
                          ->where('treatment_slug', $treatFor)->first();
        }
        if (! $form && $serviceFor) {
            $form = $base()->where('service_slug', $serviceFor)
                           ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                           ->first();
        }
        if (! $form && $serviceFor) {
            $svc = \App\Models\Service::query()->where('slug', $serviceFor)->first();
            if ($svc && $svc->clinicalNotesForm) $form = $svc->clinicalNotesForm;
        }
        if (! $form) {
            $form = $base()->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
                           ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                           ->first();
        }
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
                    foreach (['label','key','placeholder','help','description','required','options','content','accept','multiple'] as $k) {
                        if (array_key_exists($k, $data)) $field[$k] = $data[$k];
                    }
                    $current['fields'][] = $field;
                }
            }
            if (!empty($current['fields'])) $sections[] = $current;
        }
    }

    // Load last saved data for prefill
    $oldData = [];
    if ($form && isset($sessionLike->id)) {
        $resp = \App\Models\ConsultationFormResponse::query()
            ->where('consultation_session_id', $sessionLike->id)
            ->where('clinic_form_id', $form->id)
            ->latest('id')
            ->first();
        $oldData = $resp?->data ?? [];
    }

    // Helper to normalise options
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

    // Pull Admin Notes from the related Approved Order so we can prefill a field at the bottom
    $order = null;
    $adminNotes = '';
    try {
        if ($sessionLike && method_exists($sessionLike, 'order')) {
            $order = $sessionLike->order; // lazy-load OK in Blade
        }
        if ($order) {
            // direct column, if present
            if (isset($order->admin_notes) && is_string($order->admin_notes) && trim($order->admin_notes) !== '') {
                $adminNotes = (string) $order->admin_notes;
            }
            // look through meta for common note paths
            $metaArr = is_array($order->meta ?? null) ? $order->meta : [];
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
    } catch (Throwable $e) {
        // fail silent – admin notes are optional
    }

    // --- Defaults from the first order line (for prefill) ---
    $lineItems = is_array($order->items ?? null) ? $order->items : [];
    $firstItem = $lineItems[0] ?? [];
    $defaultVaccine = (string) ($firstItem['name'] ?? '');
    $defaultSpecific = (string) (
        $firstItem['variation']
        ?? $firstItem['variations']
        ?? $firstItem['optionLabel']
        ?? $firstItem['strength']
        ?? $firstItem['dose']
        ?? ''
    );
    $defaultQty = (int) ($firstItem['qty'] ?? $firstItem['quantity'] ?? 0);
@endphp

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
      .cf-field-flat{border:0;padding:0}
      .cf-title{font-weight:600;font-size:16px;margin:0 0 6px 0}
      .cf-summary{font-size:13px;margin:0}
      .cf-label{font-size:14px;display:block;margin-bottom:6px}
      .cf-help{font-size:12px;margin-top:6px}
      .cf-checkbox-row{display:flex;align-items:center;gap:10px}
      .cf-ul{list-style:disc;padding-left:20px;margin:0}
      .cf-ul li{margin:4px 0}
      .cf-paras p{margin:8px 0;line-height:1.6}
      .cf-signature{display:block}
      .cf-signature-canvas{width:100%;height:180px;border:1px dashed rgba(0,0,0,.25);border-radius:8px;background:transparent;touch-action:none}
      .cf-signature-actions{margin-top:8px;display:flex;gap:8px}
      .cf-btn{appearance:none;border:1px solid rgba(0,0,0,.25);background:transparent;border-radius:8px;padding:6px 10px;font-size:12px;cursor:pointer}
      .cf-btn:hover{background:rgba(0,0,0,.04)}
      @media(min-width:768px){.cf-section-card{padding:28px}.cf-grid{gap:20px}.cf-field-card{padding:20px}}
      .cf-input, .cf-select, .cf-file{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px}
      .cf-textarea{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px;min-height:140px;resize:vertical}
      .cf-input:focus, .cf-textarea:focus, .cf-select:focus{outline:none;border-color:rgba(255,255,255,.28);box-shadow:0 0 0 2px rgba(255,255,255,.12)}
      .cf-section-card *, .cf-field-card *{box-sizing:border-box;max-width:100%}
      @media(min-width:900px){.cf-grid{grid-template-columns:1fr 1fr}.cf-span-2{grid-column:1 / -1}}
    </style>
@endonce

@if (! $form)
    <div class="rounded-md border border-red-300 bg-red-50 text-red-800 p-4">
        <div class="font-semibold">Record of Supply form not found</div>
        <div class="mt-1 text-sm">
            Please create an active Clinical Notes ClinicForm for
            <code>{{ $serviceFor ?? 'any service' }}</code>
            {{ $treatFor ? 'and treatment ' . $treatFor : '' }}
            or assign a Clinical Notes form to this service.
        </div>
    </div>
@else
    <form id="cf_record-of-supply" data-cf-form="1" data-step="record-of-supply" method="POST" enctype="multipart/form-data" action="{{ route('consultations.forms.save', ['session' => $sessionLike->id ?? ($session->id ?? null), 'form' => $form->id]) }}?tab=record-of-supply">
        @csrf
        <input type="hidden" name="form_type" value="clinical_notes">
        <input type="hidden" name="__step_slug" value="record-of-supply">
        <input type="hidden" id="__go_next" name="__go_next" value="0">
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
                    @php
                        $onlyOne = count($section['fields'] ?? []) === 1;
                        $cardBase = $onlyOne ? 'cf-field-flat' : 'cf-field-card';
                    @endphp
                    @foreach (($section['fields'] ?? []) as $i => $field)
                        @php
                            $type  = $field['type'] ?? 'text_input';
                            $label = $field['label'] ?? null;
                            $key   = $field['key'] ?? ($label ? \Illuminate\Support\Str::slug($label) : ('field_'.$loop->index));
                            // Always use a slugged name so it matches validation rules (eg scale_photo -> scale-photo)
                            $name  = \Illuminate\Support\Str::slug((string) $key);
                            $help  = $field['help'] ?? ($field['description'] ?? null);
                            $req   = (bool) ($field['required'] ?? false);
                            $ph    = $field['placeholder'] ?? null;
                            // Try both the slugged key and the original key when prefilling saved data
                            $val   = old($name, $oldData[$name] ?? ($oldData[$key] ?? ''));
                            // For date fields, default to today's date if nothing saved yet
                            if ($type === 'date' && $val === '') {
                                $val = now()->format('Y-m-d');
                            }
                            // Slug variants of key and label for matching special fields (vaccine, specific etc)
                            $slugKey   = \Illuminate\Support\Str::slug($key);
                            $slugLabel = \Illuminate\Support\Str::slug($label ?? '');
                            $isVaccineKey = in_array('vaccine', [$slugKey, $slugLabel], true);
                            $isSpecificKey = in_array('specific-vaccine', [$slugKey, $slugLabel], true)
                                             || in_array('specific', [$slugKey, $slugLabel], true);

                            if ($val === '' && ($isVaccineKey || $isSpecificKey)) {
                                if ($isVaccineKey) {
                                    // For selects, choose the matching option value by label/value/slug
                                    if (($type === 'select') && isset($field['options'])) {
                                        foreach ($normaliseOptions($field['options']) as $op) {
                                            if (
                                                strcasecmp((string)$op['label'], (string)$defaultVaccine) === 0
                                                || \Illuminate\Support\Str::slug((string)$op['label']) === \Illuminate\Support\Str::slug((string)$defaultVaccine)
                                                || (string)$op['value'] === (string)$defaultVaccine
                                                || \Illuminate\Support\Str::slug((string)$op['value']) === \Illuminate\Support\Str::slug((string)$defaultVaccine)
                                            ) {
                                                $val = (string)$op['value'];
                                                break;
                                            }
                                        }
                                    }
                                    // For non-selects, just use the readable name
                                    if ($val === '') { $val = $defaultVaccine; }
                                } elseif ($isSpecificKey) {
                                    // For selects, choose the matching option value; else set text
                                    if (($type === 'select') && isset($field['options'])) {
                                        foreach ($normaliseOptions($field['options']) as $op) {
                                            if (
                                                strcasecmp((string)$op['label'], (string)$defaultSpecific) === 0
                                                || \Illuminate\Support\Str::slug((string)$op['label']) === \Illuminate\Support\Str::slug((string)$defaultSpecific)
                                                || (string)$op['value'] === (string)$defaultSpecific
                                                || \Illuminate\Support\Str::slug((string)$op['value']) === \Illuminate\Support\Str::slug((string)$defaultSpecific)
                                            ) {
                                                $val = (string)$op['value'];
                                                break;
                                            }
                                        }
                                    }
                                    if ($val === '') { $val = $defaultSpecific; }
                                }
                            }

                            // Prefill quantity from order line when field key/label looks like quantity
                            $isQtyKey = in_array('quantity', [$slugKey, $slugLabel], true) || in_array('qty', [$slugKey, $slugLabel], true);
                            if ($val === '' && $isQtyKey && $defaultQty) {
                                $val = (string) $defaultQty;
                            }

                            // Prefill item and item-variation using the same logic as vaccine/specific; fallback to "other" if still empty
                            $isItemKey = in_array('item', [$slugKey, $slugLabel], true);
                            $isItemVarKey = in_array('item-variation', [$slugKey, $slugLabel], true) || in_array('item-variant', [$slugKey, $slugLabel], true);

                            if ($val === '' && ($isItemKey || $isItemVarKey)) {
                                $target = $isItemKey ? (string) $defaultVaccine : (string) $defaultSpecific;

                                if ($target !== '') {
                                    if (($type === 'select') && isset($field['options'])) {
                                        foreach ($normaliseOptions($field['options']) as $op) {
                                            if (
                                                strcasecmp((string)$op['label'], $target) === 0
                                                || \Illuminate\Support\Str::slug((string)$op['label']) === \Illuminate\Support\Str::slug($target)
                                                || (string)$op['value'] === $target
                                                || \Illuminate\Support\Str::slug((string)$op['value']) === \Illuminate\Support\Str::slug($target)
                                            ) {
                                                $val = (string) $op['value'];
                                                break;
                                            }
                                        }
                                    }
                                    // For non-selects or if no select match, use the readable target
                                    if ($val === '') { $val = $target; }
                                }

                                // Final fallback to an "other" option if present, else literal 'other'
                                if ($val === '') {
                                    if (($type === 'select') && isset($field['options'])) {
                                        foreach ($normaliseOptions($field['options']) as $op) {
                                            $valSlug = strtolower((string) $op['value']);
                                            $labSlug = \Illuminate\Support\Str::slug((string) $op['label']);
                                            if (in_array($valSlug, ['other','others'], true) || in_array($labSlug, ['other','others'], true)) {
                                                $val = (string) $op['value'];
                                                break;
                                            }
                                        }
                                    }
                                    if ($val === '') { $val = 'other'; }
                                }
                            }

                            $spanClass = in_array($type, ['textarea','signature']) ? ' cf-span-2' : '';
                        @endphp

                        {{-- Static rich text blocks --}}
                        @if ($type === 'text_block')
                            @php
                                $html  = (string) ($field['content'] ?? '');
                                $align = (string) ($field['align'] ?? 'left');
                                $hasHtml = (bool) preg_match('/<\w+[^>]*>/', $html ?? '');
                            @endphp
                            @if (trim(strip_tags($html)) !== '')
                                <div class="{{ $cardBase }} cf-span-2" style="text-align: {{ $align }};">
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
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label class="cf-label">{{ $label }}</label>
                                @endif
                                <div class="flex flex-wrap items-center -m-2">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $idx => $op)
                                        @php $rid = $name.'_'.$idx; @endphp
                                        <label for="{{ $rid }}" class="p-2 inline-flex items-center gap-2 text-sm">
                                            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $op['value'] }}" class="rounded-full focus:ring-2" {{ (string)$val === (string)$op['value'] ? 'checked' : '' }}>
                                            <span>{{ $op['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'textarea')
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="6" placeholder="{{ $ph }}" @if($req) required @endif class="cf-textarea">{{ $val }}</textarea>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'select')
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <select id="{{ $name }}" name="{{ $name }}" @if($req) required @endif class="cf-input">
                                    <option value="" @if($req) disabled @endif @if(!strlen((string)$val)) selected @endif>Please select</option>
                                    @foreach($normaliseOptions($field['options'] ?? []) as $op)
                                        <option value="{{ $op['value'] }}" {{ (string)$val === (string)$op['value'] ? 'selected' : '' }}>{{ $op['label'] }}</option>
                                    @endforeach
                                </select>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'checkbox')
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                <div class="cf-checkbox-row">
                                    <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="rounded-md focus:ring-2 mt-0.5" {{ $val ? 'checked' : '' }}>
                                    <label for="{{ $name }}" class="text-sm cursor-pointer select-none leading-6">{{ $label }}</label>
                                </div>
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'date')
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="date" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" @if($req) required @endif class="cf-input" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'signature')
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label class="cf-label">{{ $label }}</label>
                                @endif
                                <div class="cf-signature" data-name="{{ $name }}">
                                    <canvas id="sig_{{ $name }}" class="cf-signature-canvas"></canvas>
                                    <div class="cf-signature-actions">
                                        <button type="button" class="cf-btn" data-clear="#sig_{{ $name }}">Clear</button>
                                    </div>
                                    <input type="hidden" name="{{ $name }}" id="input_{{ $name }}" value="{{ is_string($val) ? $val : '' }}">
                                </div>
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'file')
                            @php
                                $accept   = $field['accept'] ?? null;
                                $multiple = (bool) ($field['multiple'] ?? false);
                            @endphp
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="file" id="{{ $name }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="cf-file" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @else
                            <div class="{{ $cardBase }}{{ $spanClass }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" placeholder="{{ $ph }}" @if($req) required @endif class="cf-input" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @endif
                    @endforeach
                </div>
                </div>
            @endforeach

            {{-- Other clinical notes --}}
            <div class="cf-section-card">
                <div class="mb-4">
                    <h3 class="cf-title">Other clinical notes</h3>
                </div>
                <div class="cf-grid">
                    <div class="cf-field-flat cf-span-2">
                        <input
                            type="text"
                            id="other_clinical_notes"
                            name="other_clinical_notes"
                            value="{{ old('other_clinical_notes', $oldData['other_clinical_notes'] ?? '') }}"
                            placeholder="Add any additional clinical notes"
                            class="cf-input"
                        />
                    </div>
                </div>
            </div>
            {{-- Admin Notes imported from Approved Order --}}
            <div class="cf-section-card">
                <div class="mb-4">
                    <h3 class="cf-title">Admin notes</h3>
                </div>
                <div class="cf-grid">
                    <div class="cf-field-flat cf-span-2">
                        <textarea id="admin_notes" name="admin_notes" rows="6" placeholder="Admin notes from order" class="cf-textarea">{{ old('admin_notes', $adminNotes) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
          function initSig(box){
            var canvas = box.querySelector('canvas');
            var input = box.querySelector('input[type="hidden"]');
            if(!canvas||!input) return;
            var ctx = canvas.getContext('2d');
            var drawing = false; var last = null;
            function dpr(){ return window.devicePixelRatio || 1; }
            function size(){
              var ratio = dpr();
              var rectW = box.clientWidth || 600;
              var rectH = 180;
              canvas.width = Math.floor(rectW * ratio);
              canvas.height = Math.floor(rectH * ratio);
              canvas.style.width = rectW + 'px';
              canvas.style.height = rectH + 'px';
              ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
              ctx.lineWidth = 2;
              ctx.lineJoin = 'round';
              ctx.lineCap = 'round';
              if(input.value && /^data:image\//.test(input.value)){
                var img = new Image();
                img.onload = function(){ ctx.drawImage(img, 0, 0, rectW, rectH); };
                img.src = input.value;
              }
            }
            function pos(e){
              var r = canvas.getBoundingClientRect();
              var x,y; if(e.touches && e.touches[0]){ x=e.touches[0].clientX; y=e.touches[0].clientY; } else { x=e.clientX; y=e.clientY; }
              return { x: x - r.left, y: y - r.top };
            }
            function start(e){ drawing = true; last = pos(e); e.preventDefault(); }
            function move(e){ if(!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; e.preventDefault(); }
            function end(){ if(!drawing) return; drawing = false; try { input.value = canvas.toDataURL('image/png'); } catch(err) {} }
            canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, {passive:false}); canvas.addEventListener('touchmove', move, {passive:false}); canvas.addEventListener('touchend', end);
            window.addEventListener('resize', function(){ size(); });
            size();
            var clearBtn = box.querySelector('[data-clear]');
            if(clearBtn){ clearBtn.addEventListener('click', function(){ ctx.clearRect(0,0,canvas.width,canvas.height); input.value=''; size(); }); }
            var form = box.closest('form');
            if(form){ form.addEventListener('submit', function(){ try { input.value = canvas.toDataURL('image/png'); } catch(err) {} }); }
          }
          document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.cf-signature').forEach(initSig);
          });
        })();
        </script>

    </form>
@endif