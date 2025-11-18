

{{-- resources/views/consultations/reorder.blade.php --}}
{{-- Service-first Reorder page that renders the Reorder ClinicForm assigned to the service  mirroring risk-assessment layout and logic --}}

@php
    $sessionLike = $session ?? null;

    // Slug helpers
    $slugify = function ($v) {
        return $v ? \Illuminate\Support\Str::slug((string) $v) : null;
    };

    // Resolve service and treatment for matching
    $serviceFor = $slugify($serviceSlugForForm ?? ($sessionLike->service_slug ?? ($sessionLike->service ?? null)));
    $treatFor   = $slugify($treatmentSlugForForm ?? ($sessionLike->treatment_slug ?? ($sessionLike->treatment ?? null)));

    // Public upload URL helper resolves any storage path to the API preview endpoint
    $apiBase = config('services.pharmacy_api.base')
    ?? env('API_BASE')
    ?? env('NEXT_PUBLIC_API_BASE')
    ?? config('app.url');

    $makePublicUrl = function ($p) use ($apiBase) {
        if (!is_string($p) || $p === '') return '';

        // Absolute URL: rewrite /storage* to preview endpoint  otherwise leave as-is
        if (preg_match('/^https?:\/\/?/i', $p)) {
            $pathOnly = parse_url($p, PHP_URL_PATH) ?? '';
            if (preg_match('#/storage/app/public/(.*)$#', $pathOnly, $m)) {
                return rtrim($apiBase, '/') . '/api/uploads/view?p=' . rawurlencode($m[1]);
            }
            if (preg_match('#/storage/(.*)$#', $pathOnly, $m)) {
                return rtrim($apiBase, '/') . '/api/uploads/view?p=' . rawurlencode($m[1]);
            }
            return $p;
        }

        // Not absolute: derive the relative path under storage/app/public
        $rel = null;
        if ($rel === null && preg_match('#/storage/app/public/(.*)$#', $p, $m)) {
            $rel = $m[1];
        }
        if ($rel === null && str_starts_with($p, '/storage/')) {
            $rel = ltrim(substr($p, strlen('/storage/')), '/');
        }
        if ($rel === null) {
            $rel = ltrim($p, '/');
        }

        return rtrim($apiBase, '/') . '/api/uploads/view?p=' . rawurlencode($rel);
    };

    // Prefer template that StartConsultation placed on the session
    $form = $form ?? null;
    if (! $form && isset($sessionLike->templates)) {
        $tpl = \Illuminate\Support\Arr::get($sessionLike->templates, 'reorder');
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

    // Fallbacks by service and treatment using Reorder form_type
    if (! $form) {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'reorder')
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
            if ($svc && $svc->reorderForm) $form = $svc->reorderForm;
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

    // Normalise schema to sections with fields  same approach as risk-assessment
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
                        'showIf'  => $data['showIf'] ?? ($data['show_if'] ?? null),
                        'fields'  => [],
                    ];
                } else {
                    $field = ['type' => $type];
                    foreach (['label','key','placeholder','help','description','required','options','content','accept','multiple','showIf','align'] as $k) {
                        if (array_key_exists($k, $data)) $field[$k] = $data[$k];
                    }
                    $current['fields'][] = $field;
                }
            }
            if (!empty($current['fields'])) $sections[] = $current;
        }
    }

    // Build a flat index map of inputs to support q_N style answer keys
    $flatIndexByKey = [];
    $__globalIdx = 0;
    $__inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];
    foreach ($sections as $secTmp) {
        foreach (($secTmp['fields'] ?? []) as $fTmp) {
            $tTmp = $fTmp['type'] ?? '';
            if (!in_array($tTmp, $__inputTypes, true)) continue;
            $kTmp = $fTmp['key'] ?? ($fTmp['label'] ?? null);
            if ($kTmp) {
                $flatIndexByKey[(string) $kTmp] = 'q_' . $__globalIdx;
                $flatIndexByKey[\Illuminate\Support\Str::slug((string) $kTmp)] = 'q_' . $__globalIdx;
            }
            $__globalIdx++;
        }
    }

    // Build a canonical alias map for conditional field lookups
    $__fieldAliases = [];
    $__idxAlias = 0;
    foreach ($sections as $secTmp) {
        foreach (($secTmp['fields'] ?? []) as $fTmp) {
            $tTmp = $fTmp['type'] ?? '';
            if (!in_array($tTmp, $__inputTypes, true)) { $__idxAlias++; continue; }

            $labelTmp = $fTmp['label'] ?? null;
            $keyTmp   = $fTmp['key'] ?? null;

            // Recreate the name as the renderer does
            $nameTmp = $keyTmp ?? ($labelTmp ? \Illuminate\Support\Str::slug($labelTmp) : ('field_' . $__idxAlias));

            // Collect aliases
            $aliases = [];
            if ($keyTmp) {
                $aliases[] = (string) $keyTmp;
                $aliases[] = \Illuminate\Support\Str::slug((string) $keyTmp);
            }
            if ($labelTmp) {
                $aliases[] = \Illuminate\Support\Str::slug((string) $labelTmp);
            }
            $aliases[] = 'q_' . $__idxAlias;

            foreach (array_unique(array_filter($aliases)) as $al) {
                $__fieldAliases[\Illuminate\Support\Str::slug((string) $al)] = $nameTmp;
            }

            $__idxAlias++;
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

    // Prefill from any related order meta so questions show prior answers
    try {
        $prefill = [];

        // Helpers
        $slug = function ($s) {
            if ($s === true) return 'true';
            if ($s === false) return 'false';
            $s = is_scalar($s) ? (string) $s : '';
            $s = strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-');
        };
        $asArray = function ($maybeJson) {
            if (is_array($maybeJson)) return $maybeJson;
            if (is_string($maybeJson) && $maybeJson !== '') {
                $d = json_decode($maybeJson, true);
                if (is_array($d)) return $d;
            }
            return [];
        };

        // Locate an order record by several hints
        $order = null; $orderMeta = [];
        try {
            if (isset($sessionLike->order_id)) {
                if (class_exists('App\\Models\\ApprovedOrder')) {
                    $order = \App\Models\ApprovedOrder::find($sessionLike->order_id);
                }
                if (!$order && class_exists('App\\Models\\Order')) {
                    $order = \App\Models\Order::find($sessionLike->order_id);
                }
            }
            if (!$order && method_exists($sessionLike, 'order')) {
                $order = $sessionLike->order;
            }
            $ref = $sessionLike->reference ?? $sessionLike->order_reference ?? null;
            if (!$order && $ref) {
                if (class_exists('App\\Models\\ApprovedOrder')) {
                    $order = \App\Models\ApprovedOrder::where('reference', $ref)->first();
                }
                if (!$order && class_exists('App\\Models\\Order')) {
                    $order = \App\Models\Order::where('reference', $ref)->first();
                }
            }
            if ($order) {
                $orderMeta = $asArray($order->meta ?? []);
                $consMeta = $asArray(data_get($orderMeta, 'consultation'));
                if (!empty($consMeta)) {
                    $orderMeta = array_replace_recursive($orderMeta, $consMeta);
                }
            }
        } catch (\Throwable $e2) {}

        // Extract answers from a variety of expected meta shapes
        $extractAnswers = function ($meta) use ($slug) {
            $out = [];
            if (!is_array($meta)) return $out;

            // 1 Direct maps
            foreach ([
                'assessment.answers',
                'answers',
                'consultation.answers',
                'raf.answers',
                'reorder.answers',
                'forms.answers',
            ] as $path) {
                $m = data_get($meta, $path);
                if (is_array($m)) {
                    foreach ($m as $k => $v) {
                        if ($k === '' || $k === null) continue;
                        $out[(string) $k] = $v;
                    }
                }
            }

            // 2 Array of Q A objects in common shapes
            $rowsPaths = [
                'formsQA',
                'assessment.questions',
                'consultation.questions',
                'raf.questions',
                'reorder.questions',
                'qa',
                'questions',
                'form_responses',
            ];
            foreach ($rowsPaths as $path) {
                $rows = data_get($meta, $path);
                if (!is_array($rows)) continue;
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $q = $row['key'] ?? $row['name'] ?? $row['q'] ?? $row['question'] ?? $row['label'] ?? $row['field'] ?? null;
                    $a = $row['value'] ?? $row['answer'] ?? $row['a'] ?? $row['selected'] ?? ($row['checked'] ?? null);
                    if ($q === null) continue;
                    $key = $slug($q);
                    if (is_string($a)) {
                        $l = strtolower(trim($a));
                        if (in_array($l, ['yes','true','1','checked','on','done'], true)) $a = true;
                        elseif (in_array($l, ['no','false','0','unchecked','off'], true)) $a = false;
                    }
                    $out[$key] = $a;
                }
            }

            // 3 Deep scan fallback
            $stack = [$meta];
            while ($stack) {
                $cur = array_pop($stack);
                if (!is_array($cur)) continue;
                $isRow = false;
                $q = null; $a = null;
                foreach (['key','name','q','question','label','field'] as $qk) {
                    if (array_key_exists($qk, $cur) && is_scalar($cur[$qk])) { $q = $cur[$qk]; $isRow = true; break; }
                }
                foreach (['value','answer','a','selected','checked'] as $ak) {
                    if (array_key_exists($ak, $cur)) { $a = $cur[$ak]; break; }
                }
                if ($isRow && $q !== null && $a !== null) {
                    $out[$slug($q)] = $a;
                }
                foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; }
            }

            return $out;
        };

        $prefillFromMeta = $extractAnswers($orderMeta);

        if (!empty($prefillFromMeta)) {
            $prefill = $prefillFromMeta;
        }

        if (!empty($prefill)) {
            // Merge saved data on top of prefill so explicit user edits win
            $merged = $prefill;
            foreach ((array) $oldData as $k => $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $merged[$k] = $v;
                }
            }
            $oldData = $merged;
        }
    } catch (\Throwable $e) {
        // Swallow prefill errors to keep the page rendering
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

    // Find first non-empty answer by a list of candidate keys across saved data and prefill
    $answerFor = function ($name, $label = null) use ($oldData, $prefill, $flatIndexByKey) {
        $keys = [];

        $addKeys = function ($s) use (&$keys) {
            if (! $s) return;
            $s = (string) $s;
            $keys[] = $s;
            $keys[] = \Illuminate\Support\Str::slug($s);
            $trimQ = rtrim($s, " ?\t\n\r\0\x0B");
            $keys[] = $trimQ;
            $keys[] = \Illuminate\Support\Str::slug($trimQ);
        };

        $addKeys($name);
        $addKeys($label);

        $probe = is_scalar($name) ? (string) $name : '';
        if ($probe !== '') {
            foreach ([$probe, \Illuminate\Support\Str::slug($probe)] as $cand) {
                if (isset($flatIndexByKey[$cand])) {
                    $qIdx = $flatIndexByKey[$cand];
                    $keys[] = $qIdx;
                    $keys[] = \Illuminate\Support\Str::slug($qIdx);
                }
            }
        }

        $keys = array_values(array_unique(array_filter($keys, fn($x) => $x !== '')));

        foreach ($keys as $k) {
            if (array_key_exists($k, (array) $oldData)) {
                $v = $oldData[$k];
                if ($v !== '' && $v !== null) return $v;
            }
            if (array_key_exists($k, (array) $prefill)) {
                $v = $prefill[$k];
                if ($v !== '' && $v !== null) return $v;
            }
        }

        // Fuzzy fallback to handle small schema key drifts
        $merged = array_merge((array) $prefill, (array) $oldData);
        if (!empty($merged)) {
            $candidates = array_keys($merged);
            $norm = function($s){
                $s = is_scalar($s) ? (string)$s : '';
                $s = strtolower(trim($s));
                $s = preg_replace('/[^a-z0-9]+/','-',$s);
                $s = preg_replace('/-(details?|notes?|info(?:rmation)?|explain(?:ation)?)$/','',$s);
                return trim($s,'-');
            };
            $nameSlug  = $norm($name);
            $labelSlug = $norm($label);

            $scoreBest = 0; $bestKey = null;
            foreach ($candidates as $cand) {
                $c = $norm($cand);
                if ($c === '' || ($nameSlug === '' && $labelSlug === '')) continue;
                $tokensC   = array_filter(explode('-', $c));
                $tokensN   = array_filter(explode('-', $nameSlug));
                $tokensL   = array_filter(explode('-', $labelSlug));
                $wantTokens = !empty($tokensN) ? $tokensN : $tokensL;
                $matches = 0; $total = max(count($wantTokens), 1);
                foreach ($wantTokens as $t) {
                    foreach ($tokensC as $tc) {
                        if ($t === $tc) { $matches++; break; }
                        if (strlen($t) >= 3 && (str_starts_with($tc, $t) || str_starts_with($t, $tc))) { $matches++; break; }
                    }
                }
                $score = $matches / $total;
                if ($score > $scoreBest && $score >= 0.6) { $scoreBest = $score; $bestKey = $cand; }
            }
            if ($bestKey !== null) {
                return $merged[$bestKey];
            }
        }

        return null;
    };

    // Helper to evaluate showIf condition server-side with alias resolution
    $evaluateShowIf = function ($cond) use ($answerFor, $__fieldAliases) {
        if (!is_array($cond)) return true;
        $fieldRaw = $cond['field'] ?? null;
        if (!$fieldRaw) return true;

        // Resolve to canonical input name
        $fieldSlug = \Illuminate\Support\Str::slug((string) $fieldRaw);
        $fieldName = $__fieldAliases[$fieldSlug] ?? $__fieldAliases[$fieldRaw] ?? $fieldSlug;

        $raw = $answerFor($fieldName, $fieldName);

        $slug = function($s){
            if (is_bool($s)) return $s ? 'yes' : 'no';
            $s = is_scalar($s) ? (string)$s : '';
            $s = strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9]+/','-',$s);
            return trim($s,'-');
        };

        if (is_array($raw)) {
            $val = array_map($slug, $raw);
        } elseif ($raw === true || $raw === false) {
            $val = $slug($raw);
        } elseif ($raw === null) {
            $val = null;
        } else {
            $val = $slug($raw);
        }

        $in = array_map($slug, (array)($cond['in'] ?? []));
        $equals = isset($cond['equals']) ? $slug($cond['equals']) : null;
        $notEquals = isset($cond['notEquals']) ? $slug($cond['notEquals']) : null;
        $truthy = (bool)($cond['truthy'] ?? false);

        if (!empty($in)) {
            if (is_array($val)) return count(array_intersect($val, $in)) > 0;
            return $val !== null && in_array($val, $in, true);
        }
        if ($equals !== null) {
            if (is_array($val)) return in_array($equals, $val, true);
            return $val !== null && $val === $equals;
        }
        if ($notEquals !== null) {
            if (is_array($val)) return !empty($val) && !in_array($notEquals, $val, true);
            return $val !== null && $val !== $notEquals;
        }
        if ($truthy) {
            if (is_array($val)) return count(array_filter($val, fn($v)=>$v!=='' && $v!==null))>0;
            if (is_string($val)) return $val !== '' && $val !== 'no' && $val !== 'false' && $val !== '0';
            return (bool)$val;
        }
        return true;
    };
@endphp

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
      .cf-title{font-weight:600;font-size:16px;margin:0 0 6px 0}
      .cf-summary{font-size:13px;margin:0}
      .cf-label{font-size:14px;display:block;margin-bottom:6px}
      .cf-help{font-size:12px;margin-top:6px}
      .cf-checkbox-row{display:flex;align-items:center;gap:10px}
      .cf-ul{list-style:disc;padding-left:20px;margin:0}
      .cf-ul li{margin:4px 0}
      .cf-paras p{margin:8px 0;line-height:1.6}
      @media(min-width:768px){.cf-section-card{padding:28px}.cf-grid{gap:20px}.cf-field-card{padding:20px}}
      .cf-input, .cf-select, .cf-file{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px}
      .cf-file-input{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      .cf-file-btn{cursor:pointer}
      .cf-textarea{display:block;width:100%;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:10px;padding:10px 12px;min-height:140px;resize:vertical}
      .cf-input:focus, .cf-textarea:focus, .cf-select:focus{outline:none;border-color:rgba(255,255,255,.28);box-shadow:0 0 0 2px rgba(255,255,255,.12)}
      .cf-thumbs{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
      .cf-thumb{width:160px;height:160px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.14)}
    </style>
@endonce

@if (! $form)
    <div class="rounded-md border border-red-300 bg-red-50 text-red-800 p-4">
        <div class="font-semibold">Reorder form not found</div>
        <div class="mt-1 text-sm">
            Please create an active Reorder ClinicForm for
            <code>{{ $serviceFor ?? 'any service' }}</code>
            {{ $treatFor ? 'and treatment ' . $treatFor : '' }}
            or assign a Reorder form to this service.
        </div>
    </div>
@else
    <form id="cf_reorder" method="POST" action="{{ route('consultations.forms.save', ['session' => $session->id, 'form' => $form->id]) }}?tab=reorder">
        @csrf
        <input type="hidden" name="__step_slug" value="reorder">
        <input type="hidden" id="__go_next" name="__go_next" value="0">

        @if (request()->boolean('debug'))
            <div style="margin:16px 0;padding:10px 12px;border:1px dashed rgba(255,255,255,.25);border-radius:10px;font-size:12px;opacity:.85">
                <div>debug step reorder</div>
                <div>form_id {{ $form->id ?? 'n/a' }} schema_sections {{ count($sections) }}</div>
                @php $__seen = array_slice(array_keys((array) ($oldData ?? [])), 0, 8); @endphp
                <div>answers_found {{ count((array) ($oldData ?? [])) }} keys {{ implode(', ', $__seen) }}</div>
            </div>
        @endif

        <div class="space-y-10">
            @foreach ($sections as $section)
                @php
                    $title = $section['title'] ?? null;
                    $summary = $section['summary'] ?? null;
                    $secCond = $section['showIf'] ?? null;
                    $secVisible = $evaluateShowIf($secCond);
                    $secAttrs = 'class="cf-section-card'.($secCond ? ' cf-conditional' : '').'"';
                    if (is_array($secCond) && !empty($secCond['field'])) {
                        $vals = [];
                        if (!empty($secCond['in'])) { $vals = (array)$secCond['in']; $stype='in'; }
                        elseif (isset($secCond['equals'])) { $vals = [$secCond['equals']]; $stype='equals'; }
                        elseif (isset($secCond['notEquals'])) { $vals = [$secCond['notEquals']]; $stype='not'; }
                        elseif (!empty($secCond['truthy'])) { $vals = []; $stype='truthy'; }
                        else { $stype='equals'; }
                        $valsCsv = implode('|', array_map(fn($v)=>is_string($v)?$v:json_encode($v), $vals));
                        $secFieldRaw  = (string) ($secCond['field'] ?? '');
                        $secFieldSlug = \Illuminate\Support\Str::slug($secFieldRaw);
                        $secFieldName = $__fieldAliases[$secFieldSlug] ?? $__fieldAliases[$secFieldRaw] ?? $secFieldSlug;
                        $secAttrs .= ' data-show-field="'.e($secFieldName).'" data-show-field-raw="'.e($secFieldRaw).'" data-show-type="'.$stype.'"'.($valsCsv!==''?' data-show-values="'.e($valsCsv).'"':'');
                        if (!$secVisible) { $secAttrs .= ' style="display:none"'; }
                    }
                @endphp
                <div {!! $secAttrs !!}>
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
                            // Canonicalise field type to prevent accidental checkbox rendering
                            $rawType = strtolower((string) ($field['type'] ?? 'text_input'));
                            $typeMap = [
                                'text_input' => 'text_input',
                                'text'       => 'text_input',
                                'input'      => 'text_input',
                                'string'     => 'text_input',
                                'short_text' => 'text_input',
                                'long_text'  => 'textarea',
                                'textarea'   => 'textarea',
                                'select'     => 'select',
                                'dropdown'   => 'select',
                                'radio'      => 'radio',
                                'yesno'      => 'radio',
                                'boolean'    => 'checkbox',
                                'checkbox'   => 'checkbox',
                                'switch'     => 'checkbox',
                                'date'       => 'date',
                                'file'       => 'file',
                                'file_upload'=> 'file_upload',
                                'upload'     => 'file_upload',
                                // Preserve static / content blocks and images
                                'text_block' => 'text_block',
                                'html'       => 'text_block',
                                'content'    => 'text_block',
                                'image'      => 'image',
                            ];
                            $type  = $typeMap[$rawType] ?? 'text_input';

                            $label = $field['label'] ?? null;
                            $key   = $field['key'] ?? ($label ? \Illuminate\Support\Str::slug($label) : ('field_'.$loop->index));
                            $name  = $key;
                            $help  = $field['help'] ?? ($field['description'] ?? null);
                            $req   = (bool) ($field['required'] ?? false);
                            $ph    = $field['placeholder'] ?? null;

                            // Heuristic: if something was marked as checkbox but clearly looks like free text, treat as text input
                            if ($type === 'checkbox') {
                                $hasOptions = isset($field['options']) && is_array($field['options']) && count($field['options']) > 0;
                                $labelText  = (string) ($label ?? '');
                                $looksLikeBoolean = (bool) preg_match('/\b(confirm|agree|consent|accept|acknowledge|declaration|i\s+confirm|i\s+agree)\b/i', $labelText);
                                if (! $hasOptions && ! $looksLikeBoolean) {
                                    $type = 'text_input';
                                }
                            }

                            $valRaw = $answerFor($name, $label);
                            $val    = old($name, $valRaw ?? '');
                            $cond = $field['showIf'] ?? null;
                            $visible = $evaluateShowIf($cond);
                            $wrapperAttrs = 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"';
                            if (is_array($cond) && !empty($cond['field'])) {
                                $vals = [];
                                if (!empty($cond['in'])) { $vals = (array)$cond['in']; $ctype='in'; }
                                elseif (isset($cond['equals'])) { $vals = [$cond['equals']]; $ctype='equals'; }
                                elseif (isset($cond['notEquals'])) { $vals = [$cond['notEquals']]; $ctype='not'; }
                                elseif (!empty($cond['truthy'])) { $vals = []; $ctype='truthy'; }
                                else { $ctype='equals'; }
                                $valsCsv = implode('|', array_map(fn($v)=>is_string($v)?$v:json_encode($v), $vals));
                                $fldRaw  = (string) ($cond['field'] ?? '');
                                $fldSlug = \Illuminate\Support\Str::slug($fldRaw);
                                $fldName = $__fieldAliases[$fldSlug] ?? $__fieldAliases[$fldRaw] ?? $fldSlug;
                                $wrapperAttrs .= ' data-show-field="'.e($fldName).'" data-show-field-raw="'.e($fldRaw).'" data-show-type="'.$ctype.'"'.($valsCsv!==''?' data-show-values="'.e($valsCsv).'"':'');
                                if (!$visible) { $wrapperAttrs .= ' style="display:none"'; }
                            }
                        @endphp

                        {{-- Static rich text blocks --}}
                        @if (in_array($type, ['text_block', 'text', 'html', 'content'], true))
                            @php
                                // Support both newer TipTap doc arrays and legacy HTML / plain text
                                $rawContent = $field['content'] ?? ($field['html'] ?? ($field['text'] ?? null));
                                $align      = (string) ($field['align'] ?? 'left');
                                $html       = '';

                                if (is_array($rawContent)) {
                                    // Treat as TipTap-style doc
                                    $doc  = $rawContent;
                                    $nodes = $doc['content'] ?? [];
                                    $lines = [];

                                    if (is_array($nodes)) {
                                        foreach ($nodes as $node) {
                                            if (!is_array($node)) continue;
                                            $typeNode = $node['type'] ?? 'paragraph';
                                            $nodeText = '';

                                            // Flatten simple paragraph / heading / list item content
                                            $contentNodes = $node['content'] ?? [];
                                            if (is_array($contentNodes)) {
                                                foreach ($contentNodes as $cn) {
                                                    if (!is_array($cn)) continue;

                                                    if (($cn['type'] ?? null) === 'text' && isset($cn['text'])) {
                                                        $nodeText .= $cn['text'];
                                                        continue;
                                                    }

                                                    // Nested content (eg listItem -> paragraph -> text[])
                                                    if (isset($cn['content']) && is_array($cn['content'])) {
                                                        foreach ($cn['content'] as $sub) {
                                                            if (is_array($sub) && ($sub['type'] ?? null) === 'text' && isset($sub['text'])) {
                                                                $nodeText .= $sub['text'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }

                                            $nodeText = trim($nodeText);
                                            if ($nodeText === '') continue;

                                            if (in_array($typeNode, ['bulletList', 'orderedList', 'listItem'], true)) {
                                                $lines[] = $nodeText;
                                            } else {
                                                $lines[] = $nodeText;
                                            }
                                        }
                                    }

                                    $html = implode("\n", $lines);
                                } else {
                                    $html = (string) ($rawContent ?? '');
                                }

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
                            <div {!! $wrapperAttrs !!}>
                                @if($label)
                                    <label class="cf-label">{{ $label }}</label>
                                @endif
                                @php
                                    $valForRadio = $val;
                                    if (is_bool($valForRadio)) { $valForRadio = $valForRadio ? 'yes' : 'no'; }
                                    $valSlug = is_string($valForRadio) ? \Illuminate\Support\Str::slug($valForRadio) : $valForRadio;
                                @endphp
                                <div class="flex flex-wrap items-center -m-2">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $idx => $op)
                                        @php $rid = $name.'_'.$idx; @endphp
                                        <label for="{{ $rid }}" class="p-2 inline-flex items-center gap-2 text-sm">
                                            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $op['value'] }}" class="rounded-full focus:ring-2" {{ ((string)$valForRadio === (string)$op['value']) || ((string)$valSlug === (string)$op['value']) || ((string)$valForRadio === (string)($op['label'] ?? '')) ? 'checked' : '' }}>
                                            <span>{{ $op['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'textarea')
                            <div {!! $wrapperAttrs !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="6" placeholder="{{ $ph }}" @if($req) required @endif class="cf-textarea">{{ $val }}</textarea>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'select')
                            @php $isMultiple = (bool) ($field['multiple'] ?? false); @endphp
                            <div {!! $wrapperAttrs !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                @php
                                    $vals = $val;
                                    if ($isMultiple) {
                                        if (is_string($vals) && $vals !== '') {
                                            $decoded = json_decode($vals, true);
                                            if (is_array($decoded)) {
                                                $vals = $decoded;
                                            } else {
                                                $vals = array_map('trim', explode(',', $vals));
                                            }
                                        }
                                        $vals = is_array($vals) ? $vals : ($vals!=='' ? [$vals] : []);
                                        $valsSlug = collect($vals)->map(fn($v) => is_string($v) ? \Illuminate\Support\Str::slug($v) : $v)->all();
                                    } else {
                                        if (is_bool($vals)) { $vals = $vals ? 'yes' : 'no'; }
                                        $valSlug = is_string($vals) ? \Illuminate\Support\Str::slug($vals) : $vals;
                                    }
                                @endphp
                                <select id="{{ $name }}" name="{{ $name }}{{ $isMultiple ? '[]' : '' }}" @if($req) required @endif class="cf-input" {{ $isMultiple ? 'multiple' : '' }}>
                                    @foreach($normaliseOptions($field['options'] ?? []) as $op)
                                        @php
                                            if ($isMultiple) {
                                                $sel = in_array((string) $op['value'], array_map('strval', (array)$vals), true)
                                                    || in_array((string) $op['value'], (array) $valsSlug, true)
                                                    || in_array((string) ($op['label'] ?? ''), (array) $vals, true);
                                            } else {
                                                $sel = ((string)$vals === (string)$op['value'])
                                                    || ((string)$valSlug === (string)$op['value'])
                                                    || ((string)$vals === (string)($op['label'] ?? ''));
                                            }
                                        @endphp
                                        <option value="{{ $op['value'] }}" {{ $sel ? 'selected' : '' }}>{{ $op['label'] }}</option>
                                    @endforeach
                                </select>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                            </div>
                        @elseif ($type === 'checkbox')
                            <div {!! $wrapperAttrs !!}>
                                <div class="cf-checkbox-row">
                                    @php
                                        $checked = false;
                                        if (is_bool($val)) {
                                            $checked = $val;
                                        } elseif (is_string($val)) {
                                            $l = strtolower(trim($val));
                                            $checked = in_array($l, ['yes','true','1','checked','on','done'], true);
                                        } elseif (is_numeric($val)) {
                                            $checked = ((int) $val) === 1;
                                        }
                                    @endphp
                                    <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="rounded-md focus:ring-2 mt-0.5" {{ $checked ? 'checked' : '' }}>
                                    <label for="{{ $name }}" class="text-sm cursor-pointer select-none leading-6">{{ $label }}</label>
                                </div>
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'date')
                            <div {!! $wrapperAttrs !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="date" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" @if($req) required @endif class="cf-input" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @elseif ($type === 'file' || $type === 'file_upload' || $type === 'image')
                            @php
                                $accept   = $field['accept'] ?? null;
                                $multiple = (bool) ($field['multiple'] ?? false);

                                // Build a list of initial thumbnails with clickable hrefs from any saved value shape
                                $initialThumbs = [];

                                $extractRel = function (string $raw) {
                                    $urlPath = parse_url($raw, PHP_URL_PATH) ?: '';
                                    $query   = parse_url($raw, PHP_URL_QUERY) ?: '';
                                    if ($query && preg_match('/(?:^|&)p=([^&]+)/', $query, $m)) {
                                        return urldecode($m[1]);
                                    }
                                    if ($urlPath && preg_match('#/storage/app/public/(.*)$#', $urlPath, $m)) {
                                        return $m[1];
                                    }
                                    if ($urlPath && preg_match('#/storage/(.*)$#', $urlPath, $m)) {
                                        return $m[1];
                                    }
                                    return ltrim($urlPath !== '' ? $urlPath : $raw, '/');
                                };

                                $isImagePath = function (string $raw) use ($extractRel) {
                                    $path = $extractRel($raw);
                                    if ($path === '' || strpos($path, '/') === false) {
                                        return false;
                                    }
                                    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
                                    return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true);
                                };

                                $pushItem = function ($raw) use (&$initialThumbs, $makePublicUrl, $isImagePath, $extractRel) {
                                    if (!is_string($raw) || trim($raw) === '') return;
                                    $href = $makePublicUrl($raw);
                                    $key = $extractRel($href) ?: $extractRel($raw) ?: '';
                                    if ($key === '' || strpos($key, '/') === false) {
                                        return;
                                    }
                                    if (! $isImagePath($raw) && ! $isImagePath($href)) return;
                                    $initialThumbs[$key] = ['href' => $href, 'src' => $href];
                                };

                                if (!empty($val)) {
                                    if (is_string($val)) {
                                        $decoded = json_decode($val, true);
                                        if (is_array($decoded)) {
                                            foreach ($decoded as $vv) {
                                                if (is_string($vv)) {
                                                    $pushItem($vv);
                                                } elseif (is_array($vv)) {
                                                    $pushItem($vv['url'] ?? $vv['src'] ?? $vv['path'] ?? '');
                                                }
                                            }
                                        } else {
                                            $pushItem($val);
                                        }
                                    } elseif (is_array($val)) {
                                        foreach ($val as $vv) {
                                            if (is_string($vv)) {
                                                $pushItem($vv);
                                            } elseif (is_array($vv)) {
                                                $pushItem($vv['url'] ?? $vv['src'] ?? $vv['path'] ?? '');
                                            }
                                        }
                                    }
                                }

                                $initialThumbs = array_values($initialThumbs);
                                if (!$multiple && count($initialThumbs) > 1) {
                                    $initialThumbs = array_slice($initialThumbs, -1);
                                }
                            @endphp
                            <div {!! $wrapperAttrs !!}>
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}</label>
                                @endif
                                <input type="file" id="{{ $name }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="cf-file-input" />
                                <label for="{{ $name }}" class="cf-file cf-file-btn">Choose Files</label>

                                {{-- Existing thumbnails from saved value --}}
                                @if (!empty($initialThumbs))
                                    <div id="{{ $name }}__thumbs" class="cf-thumbs">
                                        @foreach ($initialThumbs as $it)
                                            <a href="{{ $it['href'] }}" target="_blank" rel="noopener noreferrer">
                                                <img src="{{ $it['src'] }}" alt="preview" class="cf-thumb">
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <div id="{{ $name }}__thumbs" class="cf-thumbs"></div>
                                @endif

                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                            </div>
                        @else
                            <div {!! $wrapperAttrs !!}>
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

    <script>
(function(){
  var form = document.getElementById('cf_reorder'); if (!form) return;
  var __cfAliases = @json($__fieldAliases, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  function canonicalName(s){
    if (s == null) return s;
    function _slug(x){
      if (x === true) return 'true';
      if (x === false) return 'false';
      x = (x==null?'':String(x)).toLowerCase().trim();
      return x.replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    }
    var k = _slug(s);
    return __cfAliases[k] || __cfAliases[s] || s;
  }
  function slug(s){
    if (s === true) return 'true';
    if (s === false) return 'false';
    s = (s==null?'':String(s)).toLowerCase().trim();
    return s.replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  }
  function getValue(name){
    function _slug(s){
      if (s === true) return 'true';
      if (s === false) return 'false';
      s = (s==null?'':String(s)).toLowerCase().trim();
      return s.replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
    }
    function byName(n){
      var nodes = form.querySelectorAll('[name="'+CSS.escape(n)+'"]');
      if (nodes && nodes.length) return nodes;
      return null;
    }
    var candidates = [];
    if (name) {
      candidates.push(name);
      candidates.push(_slug(name));
    }
    var nodes = null;
    for (var i=0;i<candidates.length;i++){
      nodes = byName(candidates[i]);
      if (nodes) break;
    }
    if (!nodes) {
      for (var j=0;j<candidates.length;j++){
        var elById = form.querySelector('#'+CSS.escape(candidates[j]));
        if (elById) { nodes = [elById]; break; }
      }
    }
    if (!nodes) return null;
    var el = nodes[0];
    if (el.type === 'radio'){
      var checked = form.querySelector('[name="'+CSS.escape(el.name)+'"]:checked');
      return checked ? checked.value : null;
    }
    if (el.type === 'checkbox'){
      return el.checked;
    }
    if (el.tagName === 'SELECT'){
      if (el.multiple){
        return Array.from(el.selectedOptions).map(function(o){return o.value;});
      }
      return el.value;
    }
    return el.value;
  }
  function matches(cond, val){
    if (!cond || !cond.field) return true;
    var type = cond.type || cond.cmp || cond.compare || cond.kind;
    var vals = cond.values || [];
    if (!type){
      if (cond.in) { type = 'in'; vals = cond.in; }
      else if ('equals' in cond){ type='equals'; vals=[cond.equals]; }
      else if ('notEquals' in cond){ type='not'; vals=[cond.notEquals]; }
      else if (cond.truthy){ type='truthy'; vals=[]; }
    }
    if (Array.isArray(val)){
      val = val.map(slug);
    } else if (typeof val === 'boolean'){
    } else if (val != null) {
      val = slug(val);
    }
    var want = Array.isArray(vals) ? vals.map(slug) : [slug(vals)];
    switch (type){
      case 'in':
        if (Array.isArray(val)) return val.some(function(v){ return want.includes(v); });
        return val != null && want.includes(val);
      case 'equals':
        if (Array.isArray(val)) return val.includes(want[0]);
        return val != null && val === want[0];
      case 'not':
        if (Array.isArray(val)) return val.length > 0 && !val.includes(want[0]);
        return val != null && val !== want[0];
      case 'truthy':
        if (Array.isArray(val)) return val.length > 0;
        if (typeof val === 'boolean') return val === true;
        return val != null && String(val).trim() !== '' && slug(val) !== 'no' && slug(val) !== 'false' && slug(val) !== '0';
      default:
        return true;
    }
  }
  function evaluate(){
    var nodes = form.querySelectorAll('.cf-conditional');
    nodes.forEach(function(node){
      var fieldRaw = node.getAttribute('data-show-field') || node.getAttribute('data-show-field-raw');
      var field = canonicalName(fieldRaw);
      if (!field) return;
      var type = node.getAttribute('data-show-type') || 'equals';
      var valsCsv = node.getAttribute('data-show-values') || '';
      var vals = valsCsv ? valsCsv.split('|') : [];
      var val = getValue(field);
      var ok = matches({type:type, values:vals, field:field}, val);
      node.style.display = ok ? '' : 'none';
    });
  }
  function hookFilePreviews(){
    var inputs = form.querySelectorAll('input[type="file"]');
    inputs.forEach(function(inp){
      inp.addEventListener('change', function(){
        var wrap = inp.closest('.cf-field-card') || inp.parentElement;
        var box = wrap.querySelector('#'+CSS.escape(inp.id)+'__thumbs');
        if (!box) {
          box = document.createElement('div');
          box.className = 'cf-thumbs';
          box.id = inp.id + '__thumbs';
          wrap.appendChild(box);
        }
        box.innerHTML = '';
        if (!inp.files) return;
        Array.from(inp.files).forEach(function(f){
          if (!/^image\//.test(f.type)) return;
          var reader = new FileReader();
          reader.onload = function(e){
            var img = document.createElement('img');
            img.src = e.target.result;
            img.alt = f.name || 'image';
            img.className = 'cf-thumb';
            box.appendChild(img);
          };
          reader.readAsDataURL(f);
        });
      });
    });
  }
  hookFilePreviews();
  form.addEventListener('change', evaluate, true);
  form.addEventListener('input', function(e){ if (e.target && (e.target.type==='radio' || e.target.type==='checkbox' || e.target.tagName==='SELECT')) evaluate(); }, true);
  evaluate();
})();
</script>
    </form>
@endif