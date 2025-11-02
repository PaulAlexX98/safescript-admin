

{{-- resources/views/consultations/pharmacist-advice.blade.php --}}
{{-- Fresh service-first Pharmacist Advice page that renders the ClinicForm schema assigned to the service --}}

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
        $tpl = \Illuminate\Support\Arr::get($sessionLike->templates, 'advice')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'pharmacist_advice');
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

    // Fallbacks by service and treatment
    if (! $form) {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'advice')
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
            if ($svc && $svc->adviceForm) $form = $svc->adviceForm;
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
            // Already section format
            $sections = $schema;
        } else {
            // Builder blocks format: turn into sections
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
@endphp

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
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
    <form id="cf_pharmacist-advice" method="POST" action="{{ route('consultations.forms.save', ['session' => $session->id, 'form' => $form->id]) }}?tab=pharmacist-advice">
        @csrf
        <input type="hidden" name="__step_slug" value="pharmacist-advice">
        <input type="hidden" id="__go_next" name="__go_next" value="0">

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
                            $val   = old($name, $oldData[$name] ?? '');
                        @endphp

                        {{-- Static rich text blocks --}}
                        @if ($type === 'text_block')
                            @php
                                $html  = (string) ($field['content'] ?? '');
                                $align = (string) ($field['align'] ?? 'left');
                                $alignClass = $align === 'center' ? 'text-center' : ($align === 'right' ? 'text-right' : 'text-left');
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
                            <div class="{{ $fieldCard }}">
                                @if($label)
                                    <label class="cf-label">{{ $label }}</label>
                                @endif
                                <div class="flex flex-wrap items-center -m-2">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $idx => $op)
                                        @php $rid = $name.'_'.$idx; @endphp
                                        <label for="{{ $rid }}" class="p-2 inline-flex items-center gap-2 text-sm text-gray-200">
                                            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $op['value'] }}" class="rounded-full bg-gray-800/70 border-gray-700 focus:ring-amber-500 focus:ring-2" {{ (string)$val === (string)$op['value'] ? 'checked' : '' }}>
                                            <span>{{ $op['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'textarea')
                            <div class="{{ $fieldCard }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="6" placeholder="{{ $ph }}" @if($req) required @endif class="cf-textarea">{{ $val }}</textarea>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'select')
                            <div class="{{ $fieldCard }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <select id="{{ $name }}" name="{{ $name }}" @if($req) required @endif class="cf-input">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $op)
                                        <option value="{{ $op['value'] }}" {{ (string)$val === (string)$op['value'] ? 'selected' : '' }}>{{ $op['label'] }}</option>
                                    @endforeach
                                </select>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'checkbox')
                            <div class="{{ $fieldCard }}">
                                <div class="cf-checkbox-row">
                                    <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="rounded-md bg-gray-800/70 border-gray-700 focus:ring-amber-500 focus:ring-2 mt-0.5" {{ $val ? 'checked' : '' }}>
                                    <label for="{{ $name }}" class="text-sm text-gray-200 cursor-pointer select-none leading-6">{{ $label }}</label>
                                </div>
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'date')
                            <div class="{{ $fieldCard }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="date" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" @if($req) required @endif class="cf-input" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'file')
                            @php
                                $accept   = $field['accept'] ?? null;
                                $multiple = (bool) ($field['multiple'] ?? false);
                            @endphp
                            <div class="{{ $fieldCard }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="file" id="{{ $name }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="cf-file" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @else
                            <div class="{{ $fieldCard }}">
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
        </div>

        <div class="cf-section-card">
            <div class="cf-field-card">
                <div class="cf-checkbox-row">
                    <input type="checkbox" id="__cf_check_all" class="rounded-md mt-0.5">
                    <label for="__cf_check_all" class="text-sm cursor-pointer select-none leading-6">Select all checkboxes</label>
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

          // initial state
          syncMaster();
        });
        </script>

    </form>
@endif