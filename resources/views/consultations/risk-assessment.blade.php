

{{-- resources/views/consultations/risk-assessment.blade.php --}}
{{-- Service-first Risk Assessment page that renders the RAF ClinicForm assigned to the service, matching Pharmacist Advice layout --}}

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
    $apiBase = env('API_BASE')
        ?: (config('services.pharmacy_api.base') ?: config('app.url'));

    // If we are falling back to an admin subdomain, generically map admin. → api. without hardcoding any host name
    if (is_string($apiBase) && str_contains($apiBase, '://admin.')) {
        $apiBase = str_replace('://admin.', '://api.', $apiBase);
    }

    $makePublicUrl = function ($p) use ($apiBase) {
        if (!is_string($p) || $p === '') return '';

        // Absolute URL: rewrite /storage* to preview endpoint, otherwise leave as-is
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
        $tpl = \Illuminate\Support\Arr::get($sessionLike->templates, 'raf')
            ?? \Illuminate\Support\Arr::get($sessionLike->templates, 'risk_assessment');
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

    // Fallbacks by service and treatment using RAF
    if (! $form) {
        $base = fn() => \App\Models\ClinicForm::query()
            ->where('form_type', 'raf')
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
            if ($svc && $svc->rafForm) $form = $svc->rafForm;
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

    // Normalise schema to sections with fields (same as pharmacist-advice)
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
    // Maps various references (key, slug(key), slug(label), q_N) -> the actual input name we render
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

        $rawData = $resp?->data ?? [];

        // Normalise to plain array regardless of cast shape
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : [];
        } elseif (is_object($rawData)) {
            $rawData = json_decode(json_encode($rawData), true) ?: [];
        }

        $oldData = (array) ($rawData ?? []);

        // If persisted as { answers: {...} }, unwrap it
        if (array_key_exists('answers', $oldData) && is_array($oldData['answers'])) {
            $oldData = $oldData['answers'];
        }
    }

    // Fallback: hydrate from session meta in any of the known stores if DB record absent
    if (empty($oldData) && isset($sessionLike->meta)) {
        $meta = is_array($sessionLike->meta) ? $sessionLike->meta : (json_decode($sessionLike->meta ?? '[]', true) ?: []);
        $ft   = $form->form_type ?? 'raf';
        $slugCur = 'risk-assessment';
        $cands = [
            "forms.$ft.answers",
            "forms.$ft.data",
            "forms.$slugCur.answers",
            "forms.$slugCur.data",
            "forms.{$form->id}.answers",
            "forms.{$form->id}.data",
            "formsQA.$ft",
            "formsQA.$slugCur",
            "forms_qa.$ft",
            "forms_qa.$slugCur",
        ];
        foreach ($cands as $path) {
            $cand = data_get($meta, $path);
            if (is_string($cand)) {
                $decoded = json_decode($cand, true);
                if (is_array($decoded) && !empty($decoded)) { $oldData = $decoded; break; }
            }
            if (is_array($cand) && !empty($cand)) { $oldData = $cand; break; }
        }
        if (is_array($oldData) && array_key_exists('answers', $oldData) && is_array($oldData['answers'])) {
            $oldData = $oldData['answers'];
        }
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
                // Try ApprovedOrder then Order by id
                if (class_exists('App\\Models\\ApprovedOrder')) {
                    $order = \App\Models\ApprovedOrder::find($sessionLike->order_id);
                }
                if (!$order && class_exists('App\\Models\\Order')) {
                    $order = \App\Models\Order::find($sessionLike->order_id);
                }
            }
            // Try explicit relation if present
            if (!$order && method_exists($sessionLike, 'order')) {
                $order = $sessionLike->order;
            }
            // Try by reference fields
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
                // Also merge in any nested consultation meta commonly used
                $consMeta = $asArray(data_get($orderMeta, 'consultation'));
                if (!empty($consMeta)) {
                    $orderMeta = array_replace_recursive($orderMeta, $consMeta);
                }
            }
        } catch (\Throwable $e2) {
            // ignore order lookup failures
        }

        // Extract answers from a variety of expected meta shapes
        $extractAnswers = function ($meta) use ($slug) {
            $out = [];
            if (!is_array($meta)) return $out;

            // 1) Direct maps
            foreach ([
                'assessment.answers',
                'answers',
                'consultation.answers',
                'raf.answers',
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

            // 2) Array of Q A objects in common shapes
            $rowsPaths = [
                'formsQA',
                'assessment.questions',
                'consultation.questions',
                'raf.questions',
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
                    // Normalise booleanish answers
                    if (is_string($a)) {
                        $l = strtolower(trim($a));
                        if (in_array($l, ['yes','true','1','checked','on','done'], true)) $a = true;
                        elseif (in_array($l, ['no','false','0','unchecked','off'], true)) $a = false;
                    }
                    $out[$key] = $a;
                }
            }

            // 3) Deep scan fallback for nested arrays with question/answer keys
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
                // push children
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

    // Find first non-empty answer by a strict list of candidate keys using canonical aliases, no fuzzy matching.
    $answerFor = function ($name, $label = null) use ($oldData, $prefill, $flatIndexByKey, $__fieldAliases) {
        // Build a strict set of candidate keys for this field only
        $cands = [];

        // Ensure we always have array shapes even if a stdClass was loaded
        $__oldArr = (array) ($oldData ?? []);
        $__preArr = (array) ($prefill ?? []);

        $add = function ($s) use (&$cands) {
            if ($s === null || $s === '') return;
            $s = (string) $s;
            $slug = \Illuminate\Support\Str::slug($s);
            $cands[] = $s;                    // raw
            $cands[] = $slug;                  // slugged hyphen
            $cands[] = str_replace('_','-',$s); // underscore to hyphen
            $cands[] = str_replace('-','_', $slug); // hyphen to underscore
        };

        // Canonical from name then label
        $add($name);
        $add($label);

        // If we have an alias mapping from the schema use it
        if ($name) {
            $ns = \Illuminate\Support\Str::slug((string) $name);
            if (isset($__fieldAliases[$ns])) $add($__fieldAliases[$ns]);
            if (isset($__fieldAliases[$name])) $add($__fieldAliases[$name]);
        }
        if ($label) {
            $ls = \Illuminate\Support\Str::slug((string) $label);
            if (isset($__fieldAliases[$ls])) $add($__fieldAliases[$ls]);
            if (isset($__fieldAliases[$label])) $add($__fieldAliases[$label]);
        }

        // Bridge legacy q_N keys derived from this very schema only
        foreach ([$name, $label] as $probe) {
            if (!$probe) continue;
            $probe = (string) $probe;
            foreach ([$probe, \Illuminate\Support\Str::slug($probe)] as $cand) {
                if (isset($flatIndexByKey[$cand])) {
                    $q = $flatIndexByKey[$cand];
                    $cands[] = $q;
                    $cands[] = \Illuminate\Support\Str::slug($q);
                }
            }
        }

        // De-duplicate while preserving order
        $keys = [];
        foreach ($cands as $k) {
            if ($k === '' || $k === null) continue;
            if (!in_array($k, $keys, true)) $keys[] = $k;
        }

        // First prefer explicit saved data then prefill
        foreach ($keys as $k) {
            if (array_key_exists($k, $__oldArr)) {
                $v = $__oldArr[$k];
                if ($v !== '' && $v !== null) return $v;
            }
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $__preArr)) {
                $v = $__preArr[$k];
                if ($v !== '' && $v !== null) return $v;
            }
        }

        // Nothing matched for this field
        return null;
    };

    // BMI helpers
    $slugText = function ($s) {
        if ($s === true) return 'true';
        if ($s === false) return 'false';
        $s = is_scalar($s) ? (string) $s : '';
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    };

    $isBmiField = function ($field) use ($slugText) {
        $key = $field['key'] ?? null;
        $label = $field['label'] ?? null;
        $keySlug = $key ? $slugText($key) : '';
        $labelSlug = $label ? $slugText($label) : '';

        if ($keySlug === 'bmi') return true;
        if ($labelSlug === 'bmi') return true;
        if (str_contains($labelSlug, 'body-mass-index')) return true;
        if (preg_match('/\bbmi\b/i', (string) ($label ?? ''))) return true;

        return false;
    };

        $isGpField = function ($field) {
        $key = $field['key'] ?? null;
        $label = $field['label'] ?? null;

        $slug = function ($s) {
            if ($s === true) return 'true';
            if ($s === false) return 'false';
            $s = is_scalar($s) ? (string) $s : '';
            $s = strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9]+/', '-', $s);
            return trim($s, '-');
        };

        $keySlug = $key ? $slug($key) : '';
        $labelSlug = $label ? $slug($label) : '';

        $emailish = [
            'gp-email',
            'gp-email-preferred',
            'gp-email-address',
            'gp_email',
            'gpemail',
            'email',
            'email-address',
        ];

        foreach ($emailish as $bad) {
            $badSlug = $slug($bad);
            if ($keySlug === $badSlug || $labelSlug === $badSlug) return false;
            if ($keySlug !== '' && str_contains($keySlug, $badSlug)) return false;
            if ($labelSlug !== '' && str_contains($labelSlug, $badSlug)) return false;
        }

        $exactKeys = [
            'gp',
            'gp-practice',
            'gp-surgery',
            'gp-name',
            'gp-address',
            'gp-name-address',
            'search-gp',
            'search-the-name-and-address-of-your-gp',
        ];

        if (in_array($keySlug, $exactKeys, true)) return true;
        if (in_array($labelSlug, $exactKeys, true)) return true;

        if (preg_match('/\bsearch\b.*\bgp\b/i', (string) ($label ?? ''))) return true;
        if (preg_match('/\bname\b.*\baddress\b.*\bgp\b/i', (string) ($label ?? ''))) return true;
        if (preg_match('/\bgp\b.*\bname\b.*\baddress\b/i', (string) ($label ?? ''))) return true;

        return false;
    };

    // Helper to evaluate showIf condition (server-side render) with alias resolution
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
      .cf-field-card.is-required{border-color:rgba(34,197,94,.45);box-shadow:0 0 0 1px rgba(34,197,94,.12) inset}
      .cf-title{font-weight:600;font-size:16px;margin:0 0 6px 0}
      .cf-summary{font-size:13px;margin:0}
      .cf-label{font-size:14px;display:block;margin-bottom:6px}
      .cf-required{color:#22c55e;font-weight:700}
      .cf-required-banner{margin:0 0 16px 0;padding:12px 14px;border:1px solid rgba(34,197,94,.45);border-radius:12px;background:rgba(34,197,94,.10);color:#22c55e;font-weight:700}
      .cf-required-banner.is-hidden{display:none}
      .cf-help{font-size:12px;margin-top:6px}
      .cf-checkbox-row{display:flex;align-items:flex-start;gap:12px;margin-top:6px}
      /* Checkbox pill (no :has dependency) */
      .cf-check-option{display:inline-flex}
      .cf-check-input{position:absolute;opacity:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      .cf-check-pill{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.035);cursor:pointer;user-select:none;transition:background .15s ease,border-color .15s ease,transform .08s ease}
      .cf-check-pill:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.26)}
      .cf-check-pill:active{transform:translateY(1px)}
      .cf-check-box{width:20px;height:20px;border-radius:6px;border:2px solid rgba(255,255,255,.35);display:inline-flex;align-items:center;justify-content:center;transition:border-color .15s ease,background .15s ease}
      .cf-check-box::after{content:'✓';font-size:14px;line-height:1;color:#fff;opacity:0;transform:translateY(-1px);transition:opacity .15s ease}
      .cf-check-text{font-size:14px;line-height:1.4}
      .cf-check-input:focus-visible + .cf-check-pill{outline:2px solid rgba(34,197,94,.6);outline-offset:2px}
      .cf-check-input:checked + .cf-check-pill{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12)}
      .cf-check-input:checked + .cf-check-pill .cf-check-box{border-color:rgba(34,197,94,1);background:rgba(34,197,94,1)}
      .cf-check-input:checked + .cf-check-pill .cf-check-box::after{opacity:1}
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
      .cf-thumb{width:200px;height:200px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.14)}
      /* Radio pill buttons (no :has dependency) */
      .cf-radio-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}
      .cf-radio-option{display:inline-flex}
      .cf-radio-input{position:absolute;opacity:0;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
      .cf-radio-pill{display:inline-flex;align-items:center;gap:10px;padding:10px 16px;border-radius:9999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.035);cursor:pointer;user-select:none;transition:background .15s ease,border-color .15s ease,transform .08s ease}
      .cf-radio-pill:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.26)}
      .cf-radio-pill:active{transform:translateY(1px)}
      .cf-radio-pill::before{content:'';width:18px;height:18px;border-radius:9999px;border:2px solid rgba(255,255,255,.35);box-shadow:inset 0 0 0 5px transparent;transition:border-color .15s ease,box-shadow .15s ease}
      .cf-radio-input:focus-visible + .cf-radio-pill{outline:2px solid rgba(34,197,94,.6);outline-offset:2px}
      .cf-radio-input:checked + .cf-radio-pill{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12)}
      .cf-radio-input:checked + .cf-radio-pill::before{border-color:rgba(34,197,94,1);box-shadow:inset 0 0 0 5px rgba(34,197,94,1)}
      .cf-error-summary{display:none;margin:0 0 16px 0;padding:14px 16px;border:1px solid rgba(239,68,68,.45);background:rgba(127,29,29,.22);border-radius:12px}
      .cf-error-summary.is-visible{display:block}
      .cf-error-summary-title{font-weight:600;font-size:14px;margin:0 0 8px 0}
      .cf-error-summary-list{margin:0;padding-left:18px}
      .cf-error-summary-list li{margin:4px 0;font-size:13px;line-height:1.5}
      .cf-field-error{display:none;margin-top:8px;font-size:12px;color:#fca5a5}
      .cf-field-card.is-invalid .cf-field-error{display:block}
      .cf-field-card.is-invalid{border-color:rgba(239,68,68,.65)!important;box-shadow:0 0 0 2px rgba(239,68,68,.16)}
      .cf-field-card.is-invalid .cf-input,
      .cf-field-card.is-invalid .cf-textarea,
      .cf-field-card.is-invalid .cf-file,
      .cf-field-card.is-invalid .cf-radio-pill,
      .cf-field-card.is-invalid .cf-check-pill{border-color:rgba(239,68,68,.65)!important;box-shadow:0 0 0 2px rgba(239,68,68,.16)}
      .cf-bmi-wrap{display:grid;gap:14px}
      .cf-bmi-toggle{display:grid;gap:12px}
      .cf-bmi-toggle-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:9999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.035);cursor:pointer;user-select:none;transition:background .15s ease,border-color .15s ease,transform .08s ease}
      .cf-bmi-toggle-btn:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.26)}
      .cf-bmi-toggle-btn:active{transform:translateY(1px)}
      .cf-bmi-toggle-btn.is-active{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#dcfce7}
      .cf-bmi-toggle-group{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
      .cf-bmi-toggle-label{font-size:13px;font-weight:600;opacity:.9;min-width:110px}
      .cf-bmi-subgrid{display:grid;grid-template-columns:1fr;gap:12px}
      @media(min-width:768px){.cf-bmi-subgrid{grid-template-columns:1fr 1fr}}
      .cf-bmi-grid{display:grid;grid-template-columns:1fr;gap:12px}
      .cf-bmi-result{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.32);background:rgba(34,197,94,.08)}
      .cf-bmi-result strong{font-size:15px}
      .cf-bmi-hint{font-size:12px;opacity:.85;margin-top:4px}
      @media(min-width:768px){.cf-bmi-grid{grid-template-columns:1fr 1fr}}
      .cf-gp-wrap{display:grid;gap:12px}
      .cf-gp-results{display:grid;gap:8px}
      .cf-gp-result{display:block;width:100%;text-align:left;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.035);border-radius:12px;padding:12px 14px;cursor:pointer;transition:background .15s ease,border-color .15s ease}
      .cf-gp-result:hover{background:rgba(255,255,255,.055);border-color:rgba(255,255,255,.26)}
      .cf-gp-result-title{font-size:14px;font-weight:600;margin:0 0 4px 0}
      .cf-gp-result-meta{font-size:12px;opacity:.82;line-height:1.45}
      .cf-gp-error{font-size:12px;color:#fca5a5}
      .cf-gp-muted{font-size:12px;opacity:.78}
    </style>
@endonce

@if (! $form)
    <div class="rounded-md border border-red-300 bg-red-50 text-red-800 p-4">
        <div class="font-semibold">Risk Assessment form not found</div>
        <div class="mt-1 text-sm">
            Please create an active RAF ClinicForm for
            <code>{{ $serviceFor ?? 'any service' }}</code>
            {{ $treatFor ? 'and treatment ' . $treatFor : '' }}
            or assign a RAF form to this service.
        </div>
    </div>
@else
    <form id="cf_risk-assessment" method="POST" action="{{ route('consultations.forms.save', ['session' => $sessionLike->id ?? $session->id, 'form' => $form->id]) }}?tab=risk-assessment" enctype="multipart/form-data" novalidate>
        @csrf
        <input type="hidden" name="form_type" value="risk_assessment">
        @if($serviceFor)<input type="hidden" name="service_slug" value="{{ $serviceFor }}">@endif
        @if($treatFor)<input type="hidden" name="treatment_slug" value="{{ $treatFor }}">@endif
        <input type="hidden" name="__step_slug" value="risk-assessment">
        <input type="hidden" id="__go_next" name="__go_next" value="0">

        @if (request()->boolean('debug'))
            <div style="margin:16px 0;padding:10px 12px;border:1px dashed rgba(255,255,255,.25);border-radius:10px;font-size:12px;opacity:.85">
                <div><strong>debug</strong> step risk-assessment</div>
                <div>form_id {{ $form->id ?? 'n/a' }} schema_sections {{ count($sections) }}</div>
                @php $__seen = array_slice(array_keys((array) ($oldData ?? [])), 0, 8); @endphp
                <div>answers_found {{ count((array) ($oldData ?? [])) }} keys {{ implode(', ', $__seen) }}</div>
            </div>
        @endif

        <div id="cf_error_summary" class="cf-error-summary" role="alert" aria-live="polite">
            <div class="cf-error-summary-title">Please complete the required fields before continuing.</div>
            <ul id="cf_error_summary_list" class="cf-error-summary-list"></ul>
        </div>
        <div id="cf_required_banner" class="cf-required-banner">Please fill all the required fields</div>

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
                            $type  = $field['type'] ?? 'text_input';
                            $label = $field['label'] ?? null;
                            $key   = $field['key'] ?? ($label ? \Illuminate\Support\Str::slug($label) : ('field_'.$loop->index));
                            $name  = \Illuminate\Support\Str::slug((string) $key);
                            $help  = $field['help'] ?? ($field['description'] ?? null);
                            $req   = (bool) ($field['required'] ?? false);
                            $ph    = $field['placeholder'] ?? null;
                            $valRaw = $answerFor($name, $label);
                            $val    = old($name, $valRaw ?? '');
                            $isBmi = $isBmiField($field);
                            $isGp = $isGpField($field);
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
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                @if($label)
                                    <label class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                @endif
                                @php
                                    // Normalise saved value into a comparable string
                                    $valForRadio = $val;

                                    // If saved as { value: "...", label: "..." } prefer value then label
                                    if (is_array($valForRadio)) {
                                        if (\Illuminate\Support\Arr::isAssoc($valForRadio)) {
                                            $valForRadio = $valForRadio['value'] ?? $valForRadio['label'] ?? reset($valForRadio);
                                        } else {
                                            // if it's a list, first scalar wins
                                            foreach ($valForRadio as $vv) { if (is_scalar($vv)) { $valForRadio = $vv; break; } }
                                        }
                                    }

                                    // Booleans / numeric-ish strings -> yes/no
                                    if (is_bool($valForRadio)) {
                                        $valForRadio = $valForRadio ? 'yes' : 'no';
                                    } elseif (is_scalar($valForRadio)) {
                                        $low = strtolower(trim((string) $valForRadio));
                                        if (in_array($low, ['1','true','yes','checked','on','y'], true))   $valForRadio = 'yes';
                                        elseif (in_array($low, ['0','false','no','unchecked','off','n'], true)) $valForRadio = 'no';
                                    } else {
                                        $valForRadio = '';
                                    }

                                    $valSlug = \Illuminate\Support\Str::slug((string) $valForRadio);
                                @endphp
                                <div class="cf-radio-row">
                                    @foreach($normaliseOptions($field['options'] ?? []) as $idx => $op)
                                        @php
                                            $rid   = $name.'_'.$idx;
                                            $opVal = (string) ($op['value'] ?? '');
                                            $opLab = (string) ($op['label'] ?? $opVal);
                                            $opValSlug = \Illuminate\Support\Str::slug($opVal);
                                            $opLabSlug = \Illuminate\Support\Str::slug($opLab);

                                            // Mark selected if any of the raw/slug combinations match
                                            $selected = false;
                                            if ($valSlug !== '') {
                                                $selected = ($valSlug === $opValSlug) || ($valSlug === $opLabSlug);
                                            } else {
                                                // raw fallback compare (should rarely be needed)
                                                $selected = ((string)$valForRadio === $opVal) || ((string)$valForRadio === $opLab);
                                            }
                                        @endphp
                                        <label for="{{ $rid }}" class="cf-radio-option">
                                            <input
                                                type="radio"
                                                id="{{ $rid }}"
                                                name="{{ $name }}"
                                                value="{{ $op['value'] }}"
                                                class="cf-radio-input"
                                                {{ $selected ? 'checked' : '' }}
                                            >
                                            <span class="cf-radio-pill">{{ $op['label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                                <div class="cf-field-error"></div>
                            </div>
                        @elseif ($type === 'textarea')
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                @endif
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="6" placeholder="{{ $ph }}" data-req="{{ $req ? 1 : 0 }}" class="cf-textarea">{{ $val }}</textarea>
                                @if($help)
                                    <p class="cf-help">{!! nl2br(e($help)) !!}</p>
                                @endif
                                <div class="cf-field-error"></div>
                            </div>
                        @elseif ($type === 'select')
                            @php $isMultiple = (bool) ($field['multiple'] ?? false); @endphp
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
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
                                <select id="{{ $name }}" name="{{ $name }}{{ $isMultiple ? '[]' : '' }}" data-req="{{ $req ? 1 : 0 }}" class="cf-input" {{ $isMultiple ? 'multiple' : '' }}>
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
                                <div class="cf-field-error"></div>
                            </div>
                        @elseif ($type === 'checkbox')
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
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
                                    <label for="{{ $name }}" class="cf-check-option">
                                        <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="cf-check-input" {{ $checked ? 'checked' : '' }}>
                                        <span class="cf-check-pill">
                                            <span class="cf-check-box"></span>
                                            <span class="cf-check-text">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</span>
                                        </span>
                                    </label>
                                </div>
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                                <div class="cf-field-error"></div>
                            </div>
                        @elseif ($type === 'date')
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                @endif
                                <input type="date" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" data-req="{{ $req ? 1 : 0 }}" class="cf-input" />
                                @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                                <div class="cf-field-error"></div>
                            </div>
                        @elseif ($type === 'file' || $type === 'file_upload' || $type === 'image')
                            @php
                                $accept   = $field['accept'] ?? null;
                                $multiple = (bool) ($field['multiple'] ?? false);

                                // Build a list of initial thumbnails with clickable hrefs from any saved value shape.
                                // Also de‑dupe so a single image doesn't render multiple times, preferring the most recent.
                                $initialThumbs = [];

                                // Extract the underlying relative storage path from any variant (raw path, /storage URL, or preview endpoint ?p=)
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
                                        // not a storage-like relative path eg "Screen Shot ... .png" with no folder
                                        return false;
                                    }
                                    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: '');
                                    return in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true);
                                };

                                $pushItem = function ($raw) use (&$initialThumbs, $makePublicUrl, $isImagePath, $extractRel) {
                                    if (!is_string($raw) || trim($raw) === '') return;

                                    // Normalise to our public preview URL
                                    $href = $makePublicUrl($raw);

                                    // Use the underlying relative path as the de‑dupe key. If it doesn't look like a storage path, skip.
                                    $key = $extractRel($href) ?: $extractRel($raw) ?: '';
                                    if ($key === '' || strpos($key, '/') === false) {
                                        return; // ignore bogus preview like "...?p=Screen Shot ....png"
                                    }

                                    // Only add image-like files (after the key validation)
                                    if (! $isImagePath($raw) && ! $isImagePath($href)) return;

                                    // Prefer the most recent occurrence by overwriting any previous entry for the same file
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

                                // Normalise to a simple indexed array for rendering
                                $initialThumbs = array_values($initialThumbs);
                                $hasInitial = !empty($initialThumbs);
                                $existingKeys = [];
                                if ($hasInitial) {
                                    foreach ($initialThumbs as $it) {
                                        $existingKeys[] = $extractRel($it['href'] ?? ($it['src'] ?? '')) ?: '';
                                    }
                                    // de-dup and drop empties
                                    $existingKeys = array_values(array_filter(array_unique($existingKeys)));
                                }
                                // If single-file field but multiple previews slipped in, keep only the most recent
                                if (!$multiple && count($initialThumbs) > 1) {
                                    $initialThumbs = array_slice($initialThumbs, -1);
                                }
                            @endphp
                            <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                @if($label)
                                    <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                @endif
                                <input type="file" id="{{ $name }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" @if($accept) accept="{{ $accept }}" @endif @if($multiple) multiple @endif class="cf-file-input" data-has-initial="{{ $hasInitial ? '1' : '0' }}" />
                                @if($hasInitial && !empty($existingKeys))
                                    <input type="hidden" name="{{ $name }}__existing" value='@json($existingKeys, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)'>
                                @endif
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
                                <div class="cf-field-error"></div>
                            </div>
                        @else
                            @if ($isBmi)
                                @php
                                    $metricHeight = $answerFor('height_cm', 'height_cm') ?? $answerFor('heightcm', 'heightcm');
                                    $rawHeightText = $answerFor('height', 'height');
                                    if (($metricHeight === null || $metricHeight === '') && is_string($rawHeightText)) {
                                        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*cm\b/i', (string) $rawHeightText, $m)) {
                                            $metricHeight = $m[1];
                                        }
                                    }

                                    $metricWeight = $answerFor('weight_kg', 'weight_kg') ?? $answerFor('weightkg', 'weightkg');
                                    $rawWeightText = $answerFor('weight', 'weight');
                                    if (($metricWeight === null || $metricWeight === '') && is_string($rawWeightText)) {
                                        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*kg\b/i', (string) $rawWeightText, $m)) {
                                            $metricWeight = $m[1];
                                        }
                                    }

                                    $imperialFt = $answerFor('height_ft', 'height_ft') ?? $answerFor('heightft', 'heightft') ?? $answerFor('feet', 'feet') ?? $answerFor('ft', 'ft');
                                    $imperialIn = $answerFor('height_in', 'height_in') ?? $answerFor('heightin', 'heightin') ?? $answerFor('inches', 'inches') ?? $answerFor('inch', 'inch');
                                    $imperialSt = $answerFor('weight_st', 'weight_st') ?? $answerFor('weightst', 'weightst') ?? $answerFor('stone', 'stone') ?? $answerFor('st', 'st');
                                    $imperialLb = $answerFor('weight_lb', 'weight_lb') ?? $answerFor('weightlb', 'weightlb') ?? $answerFor('weight_lbs', 'weight_lbs') ?? $answerFor('pounds', 'pounds') ?? $answerFor('lbs', 'lbs') ?? $answerFor('lb', 'lb');
                                @endphp
                                <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                    @if($label)
                                        <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                    @endif

                                    <div class="cf-bmi-wrap" data-bmi-wrap="1" data-bmi-target="{{ $name }}">
                                        <div class="cf-bmi-toggle">
                                            <div class="cf-bmi-toggle-group" role="tablist" aria-label="Height units">
                                                <span class="cf-bmi-toggle-label">Height units</span>
                                                <button type="button" class="cf-bmi-toggle-btn is-active" data-bmi-height-mode="metric">Metric</button>
                                                <button type="button" class="cf-bmi-toggle-btn" data-bmi-height-mode="imperial">Imperial</button>
                                            </div>
                                            <div class="cf-bmi-toggle-group" role="tablist" aria-label="Weight units">
                                                <span class="cf-bmi-toggle-label">Weight units</span>
                                                <button type="button" class="cf-bmi-toggle-btn is-active" data-bmi-weight-mode="metric">Metric</button>
                                                <button type="button" class="cf-bmi-toggle-btn" data-bmi-weight-mode="imperial">Imperial</button>
                                            </div>
                                        </div>

                                        <div class="cf-bmi-grid">
                                            <div class="cf-bmi-subgrid" data-bmi-height-panel="metric">
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__height_cm">Height (cm)</label>
                                                    <input type="number" step="0.1" min="0" id="{{ $name }}__height_cm" value="{{ $metricHeight }}" class="cf-input" data-bmi-height-cm="1">
                                                </div>
                                            </div>

                                            <div class="cf-bmi-subgrid" data-bmi-height-panel="imperial" style="display:none">
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__height_ft">Height (ft)</label>
                                                    <input type="number" step="1" min="0" id="{{ $name }}__height_ft" value="{{ $imperialFt }}" class="cf-input" data-bmi-height-ft="1">
                                                </div>
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__height_in">Height (in)</label>
                                                    <input type="number" step="0.1" min="0" id="{{ $name }}__height_in" value="{{ $imperialIn }}" class="cf-input" data-bmi-height-in="1">
                                                </div>
                                            </div>

                                            <div class="cf-bmi-subgrid" data-bmi-weight-panel="metric">
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__weight_kg">Weight (kg)</label>
                                                    <input type="number" step="0.1" min="0" id="{{ $name }}__weight_kg" value="{{ $metricWeight }}" class="cf-input" data-bmi-weight-kg="1">
                                                </div>
                                            </div>

                                            <div class="cf-bmi-subgrid" data-bmi-weight-panel="imperial" style="display:none">
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__weight_st">Weight (st)</label>
                                                    <input type="number" step="0.1" min="0" id="{{ $name }}__weight_st" value="{{ $imperialSt }}" class="cf-input" data-bmi-weight-st="1">
                                                </div>
                                                <div>
                                                    <label class="cf-label" for="{{ $name }}__weight_lb">Weight (lb)</label>
                                                    <input type="number" step="0.1" min="0" id="{{ $name }}__weight_lb" value="{{ $imperialLb }}" class="cf-input" data-bmi-weight-lb="1">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="cf-bmi-result">
                                            <div>
                                                <strong>BMI</strong>
                                                <div class="cf-bmi-hint">Enter your measurements and the BMI value will be calculated automatically.</div>
                                            </div>
                                            <div style="min-width:120px">
                                                <input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" placeholder="{{ $ph ?: 'BMI' }}" data-req="{{ $req ? 1 : 0 }}" class="cf-input" data-bmi-output="1" readonly />
                                            </div>
                                        </div>
                                    </div>

                                    @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                                    <div class="cf-field-error"></div>
                                </div>
                            @elseif ($isGp)
                                <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                    @if($label)
                                        <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                    @endif

                                    <div class="cf-gp-wrap" data-gp-wrap="1" data-gp-target="{{ $name }}">
                                        <input
                                            type="text"
                                            id="{{ $name }}"
                                            name="{{ $name }}"
                                            value="{{ $val }}"
                                            placeholder="{{ $ph ?: 'Search GP practice by name, postcode or town' }}"
                                            data-req="{{ $req ? 1 : 0 }}"
                                            class="cf-input"
                                            data-gp-input="1"
                                            autocomplete="off"
                                        />
                                        <div class="cf-gp-muted" data-gp-hint="1">Start typing to search for a GP practice.</div>
                                        <div class="cf-gp-error" data-gp-error="1" style="display:none"></div>
                                        <div class="cf-gp-results" data-gp-results="1" style="display:none"></div>
                                    </div>

                                    @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                                    <div class="cf-field-error"></div>
                                </div>
                            
                            @else
                                <div {!! str_replace('class="'.$fieldCard.($cond ? ' cf-conditional' : '').'"', 'class="'.$fieldCard.($cond ? ' cf-conditional' : '').($req ? ' is-required' : '').'"', $wrapperAttrs) !!} data-field-name="{{ $name }}" data-field-label="{{ e($label ?? ucfirst(str_replace(['-','_'],' ', $name))) }}" data-required="{{ $req ? 1 : 0 }}">
                                    @if($label)
                                        <label for="{{ $name }}" class="cf-label">{{ $label }}@if($req)<span class="cf-required"> * required</span>@endif</label>
                                    @endif
                                    <input type="text" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" placeholder="{{ $ph }}" data-req="{{ $req ? 1 : 0 }}" class="cf-input" />
                                    @if($help)<p class="cf-help">{!! nl2br(e($help)) !!}</p>@endif
                                    <div class="cf-field-error"></div>
                                </div>
                            @endif
                        @endif
                    @endforeach
                </div>
                </div>
            @endforeach
        </div>

    <script>
(function(){
  var form = document.getElementById('cf_risk-assessment'); if (!form) return;
  var errorSummary = document.getElementById('cf_error_summary');
  var errorSummaryList = document.getElementById('cf_error_summary_list');
  var requiredBanner = document.getElementById('cf_required_banner');
  // Canonical field aliases generated on server
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

  function debounce(fn, wait){
    var t = null;
    return function(){
      var args = arguments;
      var ctx = this;
      clearTimeout(t);
      t = setTimeout(function(){ fn.apply(ctx, args); }, wait || 250);
    };
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
    // try each candidate until we find inputs
    var nodes = null;
    for (var i=0;i<candidates.length;i++){
      nodes = byName(candidates[i]);
      if (nodes) break;
    }
    if (!nodes) {
      // fallback: try by id
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
  function isActuallyVisible(el){
    if (!el) return false;
    if (el.hidden) return false;
    var cur = el;
    while (cur && cur !== document.body) {
      var st = window.getComputedStyle(cur);
      if (st.display === 'none' || st.visibility === 'hidden') return false;
      cur = cur.parentElement;
    }
    return true;
  }
  function clearFieldError(card){
    if (!card) return;
    card.classList.remove('is-invalid');
    var box = card.querySelector('.cf-field-error');
    if (box) box.textContent = '';
  }
  function setFieldError(card, message){
    if (!card) return;
    card.classList.add('is-invalid');
    var box = card.querySelector('.cf-field-error');
    if (box) box.textContent = message || 'This field is required.';
  }
  function clearAllFieldErrors(){
    Array.from(form.querySelectorAll('.cf-field-card')).forEach(function(card){ clearFieldError(card); });
    if (errorSummaryList) errorSummaryList.innerHTML = '';
    if (errorSummary) errorSummary.classList.remove('is-visible');
  }
  function getVisibleFieldCards(){
    return Array.from(form.querySelectorAll('.cf-field-card')).filter(function(card){
      return isActuallyVisible(card);
    });
  }
  function getCardLabel(card){
    return (card && card.getAttribute('data-field-label')) || 'This field';
  }
  function cardIsComplete(card){
    if (!card || !isActuallyVisible(card)) return true;
    if (!card.classList.contains('is-required')) return true;

    var enabledInputs = Array.from(card.querySelectorAll('input, select, textarea')).filter(function(el){
      if (!el) return false;
      if (el.disabled) return false;
      if (el.type === 'hidden') return false;
      if (el.name === '__go_next') return false;
      if (el.type === 'file') return true;
      return isActuallyVisible(el);
    });
    if (!enabledInputs.length) return true;

    var radios = enabledInputs.filter(function(el){ return el.type === 'radio'; });
    if (radios.length) return radios.some(function(el){ return el.checked; });

    var checks = enabledInputs.filter(function(el){ return el.type === 'checkbox'; });
    if (checks.length) return checks.some(function(el){ return el.checked; });

    var first = enabledInputs[0];
    if (first.type === 'file') {
      var hasInitial = first.getAttribute('data-has-initial') === '1';
      var hasFiles = first.files && first.files.length > 0;
      return hasInitial || hasFiles;
    }
    if (first.tagName === 'SELECT' && first.multiple) {
      return Array.from(first.options || []).some(function(o){ return o.selected && String(o.value || '').trim() !== ''; });
    }
    if (first.tagName === 'SELECT') {
      return String(first.value || '').trim() !== '';
    }
    return String(first.value || '').trim() !== '';
  }
  function syncRequiredBanner(){
    if (!requiredBanner) return;
    var requiredCards = Array.from(form.querySelectorAll('.cf-field-card.is-required')).filter(function(card){
      return isActuallyVisible(card);
    });
    var allComplete = requiredCards.length > 0 && requiredCards.every(cardIsComplete);
    requiredBanner.classList.toggle('is-hidden', allComplete);
  }
  function validateCard(card){
    clearFieldError(card);
    if (!card || !isActuallyVisible(card)) return null;
    if (card.getAttribute('data-required') !== '1') return null;

    var enabledInputs = Array.from(card.querySelectorAll('input, select, textarea')).filter(function(el){
      if (!el) return false;
      if (el.disabled) return false;
      if (el.type === 'hidden') return false;
      if (el.name === '__go_next') return false;
      if (el.type === 'file') return true;
      return isActuallyVisible(el);
    });
    if (!enabledInputs.length) return null;

    var label = getCardLabel(card);

    var radios = enabledInputs.filter(function(el){ return el.type === 'radio'; });
    if (radios.length) {
      var checked = radios.some(function(el){ return el.checked; });
      if (!checked) {
        setFieldError(card, 'Please select an option.');
        return { card: card, label: label };
      }
      return null;
    }

    var checks = enabledInputs.filter(function(el){ return el.type === 'checkbox'; });
    if (checks.length) {
      var anyChecked = checks.some(function(el){ return el.checked; });
      if (!anyChecked) {
        setFieldError(card, 'Please tick this box to continue.');
        return { card: card, label: label };
      }
      return null;
    }

    var first = enabledInputs[0];

    if (first.type === 'file') {
      var hasInitial = first.getAttribute('data-has-initial') === '1';
      var hasFiles = first.files && first.files.length > 0;
      if (!hasInitial && !hasFiles) {
        setFieldError(card, 'Please upload a file.');
        return { card: card, label: label };
      }
      return null;
    }

    if (first.tagName === 'SELECT' && first.multiple) {
      var selected = Array.from(first.options || []).filter(function(o){ return o.selected && String(o.value || '').trim() !== ''; });
      if (!selected.length) {
        setFieldError(card, 'Please select at least one option.');
        return { card: card, label: label };
      }
      return null;
    }

    if (first.tagName === 'SELECT') {
      var selectValue = String(first.value || '').trim();
      if (!selectValue) {
        setFieldError(card, 'Please select an option.');
        return { card: card, label: label };
      }
      return null;
    }

    var value = String(first.value || '').trim();
    if (!value) {
      setFieldError(card, 'This field is required.');
      return { card: card, label: label };
    }

    return null;
  }
  function validateForm(showSummary){
    var invalids = [];
    getVisibleFieldCards().forEach(function(card){
      var result = validateCard(card);
      if (result) invalids.push(result);
    });

    if (errorSummary && errorSummaryList) {
      errorSummaryList.innerHTML = '';
      if (showSummary && invalids.length) {
        var seen = {};
        invalids.forEach(function(item){
          var key = String(item.label || '').trim().toLowerCase();
          if (!key || seen[key]) return;
          seen[key] = true;
          var li = document.createElement('li');
          li.textContent = item.label;
          errorSummaryList.appendChild(li);
        });
        errorSummary.classList.add('is-visible');
      } else if (!invalids.length) {
        errorSummary.classList.remove('is-visible');
      }
    }

    return invalids;
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
    if (Array.isArray(val)) {
      val = val.map(slug);
    } else if (typeof val === 'boolean') {
      // keep
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
  function setReqAndDisabledFor(node, visible){
    var inputs = node.querySelectorAll('input, select, textarea');
    inputs.forEach(function(el){
      var wantsReq = el.getAttribute('data-req') === '1';
      if (el.type === 'file' && el.getAttribute('data-has-initial') === '1') {
        wantsReq = false;
      }
      if (visible) {
        el.disabled = false;
        if (wantsReq) el.setAttribute('required','required'); else el.removeAttribute('required');
      } else {
        el.disabled = true;
        el.removeAttribute('required');
        clearFieldError(el.closest('.cf-field-card'));
      }
    });
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
      setReqAndDisabledFor(node, ok);
    });
    var allCards = form.querySelectorAll('.cf-field-card');
    allCards.forEach(function(card){
      if (!card.classList.contains('cf-conditional')){
        setReqAndDisabledFor(card, true);
      }
    });
    validateForm(false);
    syncRequiredBanner();
  }
    function parseCsvLine(line){
    var out = [];
    var cur = '';
    var inQuotes = false;
    for (var i = 0; i < line.length; i++) {
      var ch = line[i];
      if (ch === '"') {
        if (inQuotes && line[i + 1] === '"') {
          cur += '"';
          i++;
        } else {
          inQuotes = !inQuotes;
        }
      } else if (ch === ',' && !inQuotes) {
        out.push(cur);
        cur = '';
      } else {
        cur += ch;
      }
    }
    out.push(cur);
    return out;
  }

  function parseCsv(text){
    var lines = String(text || '').split(/\r?\n/).filter(function(line){ return String(line).trim() !== ''; });
    if (!lines.length) return [];
    var headers = parseCsvLine(lines[0]).map(function(h){ return String(h || '').trim(); });
    return lines.slice(1).map(function(line){
      var row = parseCsvLine(line);
      var obj = {};
      headers.forEach(function(h, idx){ obj[h] = row[idx] == null ? '' : row[idx]; });
      return obj;
    });
  }

  function formatPractice(item){
    var name = item.name || item.practice || item.Practice || item.organisation || item.Organisation || item.practice_name || '';
    var line1 = item.address || item.address1 || item.Address1 || item.line1 || item.street || '';
    var town = item.town || item.city || item.post_town || item.PostTown || '';
    var postcode = item.postcode || item.Postcode || item.zip || '';
    var email = item.email || item.Email || item.practice_email || '';
    var parts = [line1, town, postcode].filter(function(v){ return String(v || '').trim() !== ''; });
    return {
      title: String(name || '').trim(),
      address: parts.join(', '),
      postcode: String(postcode || '').trim(),
      email: String(email || '').trim(),
      raw: item
    };
  }

  async function searchEpracurLocal(query){
    try {
      var res = await fetch('/data/epraccur.csv', { credentials: 'same-origin' });
      if (!res.ok) return [];
      var text = await res.text();
      var rows = parseCsv(text);
      var q = slug(query || '');
      if (!q) return [];
      var results = [];
      for (var i = 0; i < rows.length; i++) {
        var row = rows[i] || {};
        var formatted = formatPractice(row);
        var hay = slug([formatted.title, formatted.address, formatted.postcode, formatted.email].join(' '));
        if (hay && hay.indexOf(q) !== -1) {
          results.push(formatted);
        }
        if (results.length >= 8) break;
      }
      return results;
    } catch (e) {
      return [];
    }
  }

  async function runGpSearch(query){
    var q = String(query || '').trim();
    if (q.length < 2) return [];

    try {
      var res = await fetch('/api/gp-search?q=' + encodeURIComponent(q), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (res.ok) {
        var data = await res.json();
        var rows = Array.isArray(data)
          ? data
          : (Array.isArray(data.items)
              ? data.items
              : (Array.isArray(data.data)
                  ? data.data
                  : (Array.isArray(data.results) ? data.results : [])));
        var mapped = rows.map(formatPractice).filter(function(item){ return item.title || item.address || item.postcode; });
        if (mapped.length) return mapped.slice(0, 8);
      }
    } catch (e) {}

    return await searchEpracurLocal(q);
  }

  function setGpEmailValue(email){
    if (!email) return;
    ['gp-email','gp_email','gpemail'].forEach(function(name){
      var el = form.querySelector('[name="' + CSS.escape(name) + '"]') || form.querySelector('#' + CSS.escape(name));
      if (el) el.value = email;
    });
  }

  function renderGpResults(resultsWrap, items, onPick){
    resultsWrap.innerHTML = '';
    if (!items || !items.length) {
      resultsWrap.style.display = 'none';
      return;
    }
    items.forEach(function(item){
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cf-gp-result';
      var title = document.createElement('div');
      title.className = 'cf-gp-result-title';
      title.textContent = item.title || 'GP Practice';
      var meta = document.createElement('div');
      meta.className = 'cf-gp-result-meta';
      meta.textContent = [item.address, item.email].filter(function(v){ return String(v || '').trim() !== ''; }).join(' • ');
      btn.appendChild(title);
      btn.appendChild(meta);
      btn.addEventListener('click', function(){ onPick(item); });
      resultsWrap.appendChild(btn);
    });
    resultsWrap.style.display = '';
  }

  function hookGpSearches(){
    var wraps = form.querySelectorAll('[data-gp-wrap="1"]');
    wraps.forEach(function(wrap){
      var input = wrap.querySelector('[data-gp-input="1"]');
      var resultsWrap = wrap.querySelector('[data-gp-results="1"]');
      var errorWrap = wrap.querySelector('[data-gp-error="1"]');
      var hintWrap = wrap.querySelector('[data-gp-hint="1"]');
      if (!input || !resultsWrap) return;

      var search = debounce(async function(){
        var q = String(input.value || '').trim();
        if (errorWrap) { errorWrap.style.display = 'none'; errorWrap.textContent = ''; }
        if (hintWrap) hintWrap.textContent = q.length < 2 ? 'Start typing to search for a GP practice.' : 'Searching GP practices…';
        if (q.length < 2) {
          resultsWrap.innerHTML = '';
          resultsWrap.style.display = 'none';
          syncRequiredBanner();
          return;
        }

        var items = await runGpSearch(q);
        if (hintWrap) hintWrap.textContent = items.length ? 'Select your GP practice from the list below.' : 'No matching GP practice found. You can keep typing manually.';
        renderGpResults(resultsWrap, items, function(item){
          var value = item.title;
          if (item.address) value += ' — ' + item.address;
          input.value = value;
          setGpEmailValue(item.email || '');
          resultsWrap.innerHTML = '';
          resultsWrap.style.display = 'none';
          if (hintWrap) hintWrap.textContent = 'GP practice selected.';
          syncRequiredBanner();
        });
      }, 250);

      input.addEventListener('input', search);
      input.addEventListener('focus', function(){
        if (String(input.value || '').trim().length >= 2) search();
      });
    });
  }
  // --- File preview hook ---
  function parseNum(v){
    if (v == null) return null;
    var n = parseFloat(String(v).replace(/,/g, '').trim());
    return Number.isFinite(n) ? n : null;
  }
  function round1(v){
    return Math.round(v * 10) / 10;
  }
  function calcBmiMetric(heightCm, weightKg){
    var h = parseNum(heightCm);
    var w = parseNum(weightKg);
    if (!(h > 0) || !(w > 0)) return null;
    var hm = h / 100;
    if (!(hm > 0)) return null;
    return round1(w / (hm * hm));
  }
  function calcBmiImperial(ft, inches, st, lb){
    var f = parseNum(ft) || 0;
    var i = parseNum(inches) || 0;
    var s = parseNum(st) || 0;
    var l = parseNum(lb) || 0;
    var totalIn = (f * 12) + i;
    var totalLb = (s * 14) + l;
    if (!(totalIn > 0) || !(totalLb > 0)) return null;
    return round1((totalLb / (totalIn * totalIn)) * 703);
  }
  function setInputValue(name, value){
    if (!name) return;
    var selector = '[name="' + CSS.escape(name) + '"]';
    var el = form.querySelector(selector);
    if (!el) {
      var byId = form.querySelector('#' + CSS.escape(name));
      if (byId) el = byId;
    }
    if (!el) return;
    el.value = value == null ? '' : String(value);
  }
  function clearNamedValues(names){
    (names || []).forEach(function(n){ setInputValue(n, ''); });
  }

  function firstFilledValue(names){
  for (var i = 0; i < (names || []).length; i++) {
    var v = getValue(names[i]);
    if (v == null) continue;
    if (Array.isArray(v)) {
      if (v.length) return v[0];
      continue;
    }
    if (String(v).trim() !== '') return v;
  }
  return '';
}

function parseMetricHeightFromText(raw){
  raw = String(raw || '').trim().toLowerCase();
  if (!raw) return null;
  var m = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*cm\b/);
  if (m) return parseNum(m[1]);
  return null;
}

function parseMetricWeightFromText(raw){
  raw = String(raw || '').trim().toLowerCase();
  if (!raw) return null;
  var m = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*kg\b/);
  if (m) return parseNum(m[1]);
  return null;
}

function parseImperialHeightFromText(raw){
  raw = String(raw || '').trim().toLowerCase();
  if (!raw) return null;
  var ft = null;
  var inches = null;
  var mFt = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*(?:ft|feet|foot)\b/);
  var mIn = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*(?:in|inch|inches)\b/);
  if (mFt) ft = parseNum(mFt[1]);
  if (mIn) inches = parseNum(mIn[1]);
  if (ft == null && inches == null) return null;
  return { ft: ft || 0, inches: inches || 0 };
}

function parseImperialWeightFromText(raw){
  raw = String(raw || '').trim().toLowerCase();
  if (!raw) return null;
  var st = null;
  var lb = null;
  var mSt = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*(?:st|stone)\b/);
  var mLb = raw.match(/([0-9]+(?:\.[0-9]+)?)\s*(?:lb|lbs|pound|pounds)\b/);
  if (mSt) st = parseNum(mSt[1]);
  if (mLb) lb = parseNum(mLb[1]);
  if (st == null && lb == null) return null;
  return { st: st || 0, lb: lb || 0 };
}

  function writeMetricAliases(heightCm, weightKg){
    var h = heightCm == null ? '' : String(heightCm);
    var w = weightKg == null ? '' : String(weightKg);
    ['height_cm','heightcm'].forEach(function(n){ setInputValue(n, h); });
    ['weight_kg','weightkg'].forEach(function(n){ setInputValue(n, w); });
    if (h) {
      ['height','height_text','height_str','patient_height'].forEach(function(n){ setInputValue(n, h + ' cm'); });
    }
    if (w) {
      ['weight','weight_text','weight_str','patient_weight'].forEach(function(n){ setInputValue(n, w + ' kg'); });
    }
  }
  function writeImperialAliases(ft, inches, st, lb){
    var f = ft == null ? '' : String(ft);
    var i = inches == null ? '' : String(inches);
    var s = st == null ? '' : String(st);
    var l = lb == null ? '' : String(lb);
    ['height_ft','heightft','height_feet','feet','ft'].forEach(function(n){ setInputValue(n, f); });
    ['height_in','heightin','height_inches','inches','inch'].forEach(function(n){ setInputValue(n, i); });
    ['weight_st','weightst','weight_stone','stone','st'].forEach(function(n){ setInputValue(n, s); });
    ['weight_lb','weightlb','weight_lbs','pounds','lbs','lb'].forEach(function(n){ setInputValue(n, l); });
    if (f || i) {
      ['height','height_text','height_str','patient_height'].forEach(function(n){ setInputValue(n, ((f || '0') + ' ft ' + (i || '0') + ' in').trim()); });
    }
    if (s || l) {
      ['weight','weight_text','weight_str','patient_weight'].forEach(function(n){ setInputValue(n, ((s || '0') + ' st ' + (l || '0') + ' lb').trim()); });
    }
  }
  
function hookBmiCalculators(){
  var wraps = form.querySelectorAll('[data-bmi-wrap="1"]');
  wraps.forEach(function(wrap){
    var output = wrap.querySelector('[data-bmi-output="1"]');
    if (!output) return;
    var targetName = wrap.getAttribute('data-bmi-target') || output.name || output.id;

    var heightMetricPanel = wrap.querySelector('[data-bmi-height-panel="metric"]');
    var heightImperialPanel = wrap.querySelector('[data-bmi-height-panel="imperial"]');
    var weightMetricPanel = wrap.querySelector('[data-bmi-weight-panel="metric"]');
    var weightImperialPanel = wrap.querySelector('[data-bmi-weight-panel="imperial"]');

    var heightMetricBtn = wrap.querySelector('[data-bmi-height-mode="metric"]');
    var heightImperialBtn = wrap.querySelector('[data-bmi-height-mode="imperial"]');
    var weightMetricBtn = wrap.querySelector('[data-bmi-weight-mode="metric"]');
    var weightImperialBtn = wrap.querySelector('[data-bmi-weight-mode="imperial"]');

    var hCm = wrap.querySelector('[data-bmi-height-cm="1"]');
    var wKg = wrap.querySelector('[data-bmi-weight-kg="1"]');
    var hFt = wrap.querySelector('[data-bmi-height-ft="1"]');
    var hIn = wrap.querySelector('[data-bmi-height-in="1"]');
    var wSt = wrap.querySelector('[data-bmi-weight-st="1"]');
    var wLb = wrap.querySelector('[data-bmi-weight-lb="1"]');

    var heightMode = 'metric';
    var weightMode = 'metric';

    var savedHeightText = firstFilledValue(['height','height_text','height_str','patient_height']);
    var savedWeightText = firstFilledValue(['weight','weight_text','weight_str','patient_weight']);

    if ((!hCm || !String(hCm.value || '').trim()) && savedHeightText) {
    var parsedMetricHeight = parseMetricHeightFromText(savedHeightText);
    var parsedImperialHeight = parseImperialHeightFromText(savedHeightText);
    if (parsedMetricHeight != null && hCm) {
        hCm.value = String(parsedMetricHeight);
    } else if (parsedImperialHeight) {
        if (hFt) hFt.value = String(parsedImperialHeight.ft || '');
        if (hIn) hIn.value = String(parsedImperialHeight.inches || '');
    }
    }

    if ((!wKg || !String(wKg.value || '').trim()) && savedWeightText) {
    var parsedMetricWeight = parseMetricWeightFromText(savedWeightText);
    var parsedImperialWeight = parseImperialWeightFromText(savedWeightText);
    if (parsedMetricWeight != null && wKg) {
        wKg.value = String(parsedMetricWeight);
    } else if (parsedImperialWeight) {
        if (wSt) wSt.value = String(parsedImperialWeight.st || '');
        if (wLb) wLb.value = String(parsedImperialWeight.lb || '');
    }
    }

    function toCm(){
      if (heightMode === 'metric') return parseNum(hCm && hCm.value);
      var ft = parseNum(hFt && hFt.value) || 0;
      var inches = parseNum(hIn && hIn.value) || 0;
      var totalIn = (ft * 12) + inches;
      if (!(totalIn > 0)) return null;
      return totalIn * 2.54;
    }

    function toKg(){
      if (weightMode === 'metric') return parseNum(wKg && wKg.value);
      var st = parseNum(wSt && wSt.value) || 0;
      var lb = parseNum(wLb && wLb.value) || 0;
      var totalLb = (st * 14) + lb;
      if (!(totalLb > 0)) return null;
      return totalLb * 0.45359237;
    }

    function applyHeightMode(nextMode){
      heightMode = nextMode === 'imperial' ? 'imperial' : 'metric';
      if (heightMetricPanel) heightMetricPanel.style.display = heightMode === 'metric' ? '' : 'none';
      if (heightImperialPanel) heightImperialPanel.style.display = heightMode === 'imperial' ? '' : 'none';
      if (heightMetricBtn) heightMetricBtn.classList.toggle('is-active', heightMode === 'metric');
      if (heightImperialBtn) heightImperialBtn.classList.toggle('is-active', heightMode === 'imperial');
      compute();
    }

    function applyWeightMode(nextMode){
      weightMode = nextMode === 'imperial' ? 'imperial' : 'metric';
      if (weightMetricPanel) weightMetricPanel.style.display = weightMode === 'metric' ? '' : 'none';
      if (weightImperialPanel) weightImperialPanel.style.display = weightMode === 'imperial' ? '' : 'none';
      if (weightMetricBtn) weightMetricBtn.classList.toggle('is-active', weightMode === 'metric');
      if (weightImperialBtn) weightImperialBtn.classList.toggle('is-active', weightMode === 'imperial');
      compute();
    }

    function compute(){
      var cm = toCm();
      var kg = toKg();
      var bmi = calcBmiMetric(cm, kg);

      if (heightMode === 'metric') {
        ['height_cm','heightcm'].forEach(function(n){ setInputValue(n, hCm && hCm.value ? hCm.value : ''); });
        if (hCm && hCm.value) {
          ['height','height_text','height_str','patient_height'].forEach(function(n){ setInputValue(n, hCm.value + ' cm'); });
        }
        clearNamedValues(['height_ft','heightft','height_feet','feet','ft','height_in','heightin','height_inches','inches','inch']);
      } else {
        ['height_ft','heightft','height_feet','feet','ft'].forEach(function(n){ setInputValue(n, hFt && hFt.value ? hFt.value : ''); });
        ['height_in','heightin','height_inches','inches','inch'].forEach(function(n){ setInputValue(n, hIn && hIn.value ? hIn.value : ''); });
        if ((hFt && hFt.value) || (hIn && hIn.value)) {
          ['height','height_text','height_str','patient_height'].forEach(function(n){ setInputValue(n, ((hFt && hFt.value) || '0') + ' ft ' + ((hIn && hIn.value) || '0') + ' in'); });
        }
        clearNamedValues(['height_cm','heightcm']);
      }

      if (weightMode === 'metric') {
        ['weight_kg','weightkg'].forEach(function(n){ setInputValue(n, wKg && wKg.value ? wKg.value : ''); });
        if (wKg && wKg.value) {
          ['weight','weight_text','weight_str','patient_weight'].forEach(function(n){ setInputValue(n, wKg.value + ' kg'); });
        }
        clearNamedValues(['weight_st','weightst','weight_stone','stone','st','weight_lb','weightlb','weight_lbs','pounds','lbs','lb']);
      } else {
        ['weight_st','weightst','weight_stone','stone','st'].forEach(function(n){ setInputValue(n, wSt && wSt.value ? wSt.value : ''); });
        ['weight_lb','weightlb','weight_lbs','pounds','lbs','lb'].forEach(function(n){ setInputValue(n, wLb && wLb.value ? wLb.value : ''); });
        if ((wSt && wSt.value) || (wLb && wLb.value)) {
          ['weight','weight_text','weight_str','patient_weight'].forEach(function(n){ setInputValue(n, ((wSt && wSt.value) || '0') + ' st ' + ((wLb && wLb.value) || '0') + ' lb'); });
        }
        clearNamedValues(['weight_kg','weightkg']);
      }

      output.value = bmi == null ? '' : String(bmi.toFixed(1));
      setInputValue(targetName, output.value);
      syncRequiredBanner();
    }

    if (heightMetricBtn) heightMetricBtn.addEventListener('click', function(){ applyHeightMode('metric'); });
    if (heightImperialBtn) heightImperialBtn.addEventListener('click', function(){ applyHeightMode('imperial'); });
    if (weightMetricBtn) weightMetricBtn.addEventListener('click', function(){ applyWeightMode('metric'); });
    if (weightImperialBtn) weightImperialBtn.addEventListener('click', function(){ applyWeightMode('imperial'); });

    [hCm, wKg, hFt, hIn, wSt, wLb].forEach(function(el){
      if (!el) return;
      el.addEventListener('input', compute);
      el.addEventListener('change', compute);
    });

    var hasImperialHeightSeed = !!(parseNum(hFt && hFt.value) || parseNum(hIn && hIn.value));
    var hasImperialWeightSeed = !!(parseNum(wSt && wSt.value) || parseNum(wLb && wLb.value));
    applyHeightMode(hasImperialHeightSeed ? 'imperial' : 'metric');
    applyWeightMode(hasImperialWeightSeed ? 'imperial' : 'metric');
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
  hookGpSearches();
  hookBmiCalculators();
  // --- End file preview hook ---
  form.addEventListener('change', evaluate, true);
  form.addEventListener('input', function(e){
    if (!e.target) return;
    if (e.target.type==='radio' || e.target.type==='checkbox' || e.target.tagName==='SELECT' || e.target.tagName==='TEXTAREA' || e.target.tagName==='INPUT') {
      evaluate();
    }
  }, true);
  form.addEventListener('submit', function(e){
    var goingNext = form.querySelector('#__go_next');
    var wantsNext = goingNext && goingNext.value === '1';
    if (!wantsNext) return;
    var invalids = validateForm(true);
    if (invalids.length) {
      e.preventDefault();
      var firstInvalid = invalids[0] && invalids[0].card;
      if (errorSummary) {
        errorSummary.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else if (firstInvalid) {
        firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      var focusTarget = firstInvalid && firstInvalid.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])');
      if (focusTarget) focusTarget.focus();
    }
  }, true);
  evaluate();
  syncRequiredBanner();
})();
</script>
    </form>
@endif