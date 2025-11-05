<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Consultation Record</title>
  <style>
    @page { margin: 22mm 18mm; }
    body { font-family: 'Times New Roman', Times, serif; color:#000; font-size:12px; }
    .header {
      display: flex;
      align-items: center; /* align title with logo baseline */
      justify-content: space-between;
      margin-top: 20px;
      margin-bottom: 28px;
      border-bottom: 2px solid #2faa3f;
      padding-bottom: 10px;
    }
    .header img { height: 38px; padding-bottom: 8px; }
    .header-right { text-align: left; }
    .title {
      font-size: 20px;
      font-weight: bold;
      color: #2faa3f;
      text-transform: uppercase;
      margin-bottom: 0; /* sit closer to the logo */
    }
    .meta { font-size: 10px; color: #444; margin-top: 4px; }
    .section-title { font-size:14px; font-weight:bold; color:#2faa3f; border-bottom:1px solid #2faa3f; margin-bottom:6px; padding-bottom:2px; }
    .panel { border:1px solid #cfcfcf; border-radius:6px; padding:10px 12px; margin-top:12px; }
    table { width:100%; border-collapse:collapse; }
    .kv td { padding:4px 0; vertical-align:top; }
    .kv td:first-child { width:120px; font-weight:bold; }
    .muted { color:#555; }
    .two-col { width:100%; border-collapse:separate; border-spacing:14px 0; }
    .two-col td { width:50%; vertical-align:top; padding:0; }
    table.items { width:100%; border-collapse:collapse; margin-top:10px; }
    table.items th { background:#e8f3e8; color:#000; text-align:left; font-weight:bold; border:1px solid #d0d0d0; padding:6px 8px; }
    table.items td { border:1px solid #d0d0d0; padding:6px 8px; }
    .right { text-align:right; }
    .total-row td { font-weight:bold; }
    .page-break { page-break-before: always; break-before: page; }
  </style>
</head>
<body>
  <div class="header">
    @if(!empty($inlineLogo))
      <img src="{{ $inlineLogo }}" alt="Pharmacy Express">
    @endif
    <div>
      <div class="title">Consultation Record</div>
      <div class="meta">Reference: {{ $ref }} | Date: {{ now()->format('d/m/Y') }}</div>
    </div>
  </div>

  <div style="margin: 10px 6px;">
    <table class="two-col">
    <tr>
      <td>
        <div class="panel">
          <div class="section-title">Pharmacy Details</div>
          <table class="kv">
            <tr><td>Name:</td><td>{{ $pharmacy['name'] }}</td></tr>
            <tr><td>Address:</td><td>{{ $pharmacy['address'] }}</td></tr>
            <tr><td>Tel:</td><td>{{ $pharmacy['tel'] }}</td></tr>
            <tr><td>Email:</td><td>{{ $pharmacy['email'] }}</td></tr>
          </table>
        </div>
      </td>
      <td>
        <div class="panel">
          <div class="section-title">Patient Information</div>
          <table class="kv">
            <tr><td>Name:</td><td>{{ $patient['name'] ?: '—' }}</td></tr>
            <tr><td>DOB:</td><td>{{ $patient['dob'] ? \Carbon\Carbon::parse($patient['dob'])->format('d/m/Y') : '—' }}</td></tr>
            <tr><td>Address:</td><td>{{ $patient['address'] ?: '—' }}</td></tr>
            <tr><td>Contact:</td><td>{{ $patient['email'] ?: '—' }} @if($patient['phone']) | {{ $patient['phone'] }} @endif</td></tr>
          </table>
        </div>
      </td>
    </tr>
    </table>
  </div>

  <div class="panel">
    <div class="section-title">Consultation Details</div>
    <div class="muted" style="margin-bottom:8px;">
      Service Details: {{ $meta['service_name'] ?? $meta['service'] ?? '—' }}
    </div>

    <table class="items">
      <thead>
        <tr>
          <th>Items</th>
          <th>Quantity</th>
          <th class="right">Unit Price</th>
          <th class="right">Total</th>
        </tr>
      </thead>
      <tbody>
        @php $total = 0; @endphp
        @foreach($items as $line)
          @php
            $qty = (int)($line['qty'] ?? $line['quantity'] ?? 1);
            $name = $line['name'] ?? $line['title'] ?? 'Service';
            $variation = $line['variation'] ?? $line['variations'] ?? $line['optionLabel'] ?? $line['strength'] ?? $line['dose'] ?? '';
            $unitMinor = $line['unitMinor'] ?? $line['unit_price_minor'] ?? null;
            $lineMinor = $line['lineTotalMinor'] ?? $line['totalMinor'] ?? null;
            $unitMajor = $unitMinor !== null ? $unitMinor/100 : ($line['unit_price'] ?? $line['price'] ?? 0);
            $lineMajor = $lineMinor !== null ? $lineMinor/100 : ($line['total'] ?? ($unitMajor * $qty));
            $unit = (float)$unitMajor;
            $lineTotal = (float)$lineMajor;
            $total += $lineTotal;
          @endphp
          <tr>
            <td>{{ $name }} @if($variation) | {{ $variation }} @endif</td>
            <td>{{ $qty }}</td>
            <td class="right">£{{ number_format($unit, 2) }}</td>
            <td class="right">£{{ number_format($lineTotal, 2) }}</td>
          </tr>
        @endforeach
        <tr class="total-row">
          <td colspan="3" class="right">Total Amount</td>
          <td class="right">£{{ number_format($total, 2) }}</td>
        </tr>
      </tbody>
    </table>
</div>

  <div class="page-break"></div>
  <div class="panel">
    @php
        // --- Resolve Risk Assessment Answers (RAF) ---
        $toArray = function ($v) {
            if (is_array($v)) return $v;
            if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; }
            if ($v instanceof \Illuminate\Contracts\Support\Arrayable) return $v->toArray();
            return [];
        };

        $metaArr = $toArray($meta ?? []);
        $rafAnswers = [];

        // Helper to pick the first non-empty array at any of the given dot paths
        $pick = function (array $arr, array $paths) {
            foreach ($paths as $p) {
                $v = data_get($arr, $p);
                if (is_array($v) && ! empty($v)) {
                    return $v;
                }
            }
            return [];
        };

        // 1) Prefer answers embedded directly in the provided $meta
        $rafAnswers = $pick($metaArr, [
            // risk assessment forms
            'forms.risk_assessment.data',
            'forms.risk_assessment.answers',
            'forms.risk_assessment.saved',
            'forms.risk_assessment.formData',
            // common shorthand keys
            'forms.risk.data',
            'forms.risk.answers',
            'forms.risk.saved',
            'forms.risk.formData',
            // raf alias
            'forms.raf.data',
            'forms.raf.answers',
            'forms.raf.saved',
            'forms.raf.formData',
            // generic "assessment"
            'forms.assessment.data',
            'forms.assessment.answers',
            'forms.assessment.saved',
            'forms.assessment.formData',
            'forms.assessment.form_data',
            // legacy or flat
            'assessment.answers',
            'answers',
        ]);

        // Session id may be present in various shapes
        $sid = data_get($metaArr, 'consultation_session_id')
            ?? data_get($metaArr, 'session_id')
            ?? data_get($metaArr, 'consultation_id')
            ?? null;

        // 2) If we have a session, read its meta which is where we persist answers now
        if (empty($rafAnswers) && $sid) {
            try {
                $sess = \App\Models\ConsultationSession::find($sid);
                if ($sess) {
                    $sessMeta = $toArray($sess->meta ?? []);
                    $rafAnswers = $pick($sessMeta, [
                        'forms.risk_assessment.data',
                        'forms.risk_assessment.answers',
                        'forms.risk_assessment.saved',
                        'forms.risk_assessment.formData',
                        'forms.risk.data',
                        'forms.risk.answers',
                        'forms.risk.saved',
                        'forms.risk.formData',
                        'forms.raf.data',
                        'forms.raf.answers',
                        'forms.raf.saved',
                        'forms.raf.formData',
                        'forms.assessment.data',
                        'forms.assessment.answers',
                        'forms.assessment.saved',
                        'forms.assessment.formData',
                        'forms.assessment.form_data',
                        'assessment.answers',
                    ]);
                }
            } catch (\Throwable $e) {
                // ignore and continue to other fallbacks
            }
        }
        // 5) Normalise row-shaped answers (list of {key, value}) into a flat map
        if (is_array($rafAnswers) && ! empty($rafAnswers)) {
            $looksList = array_keys($rafAnswers) === range(0, count($rafAnswers) - 1);
            if ($looksList) {
                $map = [];
                foreach ($rafAnswers as $row) {
                    if (!is_array($row)) continue;
                    $k = $row['key'] ?? ($row['question'] ?? ($row['label'] ?? null));
                    $v = $row['value'] ?? ($row['answer'] ?? ($row['selected'] ?? ($row['checked'] ?? ($row['raw'] ?? null))));
                    if ($k !== null) {
                        $map[(string) $k] = $v;
                    }
                }
                if (!empty($map)) {
                    $rafAnswers = $map;
                }
            }
        }

        // 3) Try the persisted ConsultationFormResponse row as another source
        if (empty($rafAnswers) && $sid) {
            try {
                /** @var \App\Models\ConsultationFormResponse|null $raf */
                $raf = \App\Models\ConsultationFormResponse::query()
                    ->where('consultation_session_id', $sid)
                    ->where(function ($q) {
                        $q->whereIn('form_type', ['risk_assessment', 'assessment', 'raf'])
                          ->orWhere('step_slug', 'like', '%risk%')
                          ->orWhere('title', 'like', '%risk%');
                    })
                    ->latest('id')
                    ->first();

                if ($raf) {
                    $raw = $raf->data;
                    $decoded = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
                    if (is_array($decoded)) {
                        $rafAnswers =
                            (array) data_get($decoded, 'data', [])
                            + (array) data_get($decoded, 'answers', [])
                            + (array) data_get($decoded, 'assessment.answers', []);
                        // If still empty and the decoded is a flat map, use it directly
                        if (empty($rafAnswers)) {
                            $rafAnswers = $decoded;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore and continue to other fallbacks
            }
        }

        // 4) Merge legacy formsQA QA rows as a supplemental source (even if $rafAnswers isn't empty)
        $qaRows = data_get($metaArr, 'formsQA.risk_assessment.qa')
            ?: data_get($metaArr, 'formsQA.assessment.qa')
            ?: data_get($metaArr, 'consultation.formsQA.risk_assessment.qa')
            ?: data_get($metaArr, 'consultation.formsQA.assessment.qa')
            ?: [];

        if (is_array($qaRows) && ! empty($qaRows)) {
            foreach ($qaRows as $row) {
                if (!is_array($row)) continue;

                $k = $row['key'] ?? ($row['question'] ?? null);
                if ($k === null) continue;

                // Try to resolve a value across common shapes
                $v = $row['raw'] ?? $row['answer'] ?? $row['value'] ?? null;

                if ($v === null && is_array($row['selected'] ?? null)) {
                    $sel = $row['selected'];
                    $v = $sel['label'] ?? $sel['value'] ?? null;
                }
                if ($v === null && is_array($row['selectedOption'] ?? null)) {
                    $sel = $row['selectedOption'];
                    $v = $sel['label'] ?? $sel['value'] ?? null;
                }
                if ($v === null && is_array($row['selection'] ?? null)) {
                    $sel = $row['selection'];
                    $v = $sel['label'] ?? $sel['value'] ?? null;
                }
                if ($v === null && is_array($row['option'] ?? null)) {
                    $opt = $row['option'];
                    $v = $opt['label'] ?? $opt['value'] ?? null;
                }
                if ($v === null && isset($row['values']) && is_array($row['values'])) {
                    $v = $row['values'];
                }
                if ($v === null && isset($row['options']) && is_array($row['options'])) {
                    $v = $row['options'];
                }

                // Only fill if missing or empty in the primary map
                $needsFill = !array_key_exists($k, $rafAnswers)
                    || $rafAnswers[$k] === ''
                    || $rafAnswers[$k] === null
                    || (is_array($rafAnswers[$k]) && empty($rafAnswers[$k]));

                if ($needsFill) {
                    $rafAnswers[$k] = $v;
                }
            }
        }

        // --- Build label map and detect file/image fields from the RAF ClinicForm schema ---
        $labelMap = [];
        $fileFieldKeys = [];

        $slugify = function ($v) {
            return \Illuminate\Support\Str::slug((string) $v);
        };

        // Attempt to resolve the RAF ClinicForm that was used for this consultation
        $form = null;

        // Prefer session-attached RAF template
        if (! $form && !empty($sid)) {
            try {
                $sess = \App\Models\ConsultationSession::find($sid);
                if ($sess && isset($sess->templates)) {
                    $tpl = \Illuminate\Support\Arr::get($sess->templates, 'raf')
                        ?? \Illuminate\Support\Arr::get($sess->templates, 'risk_assessment')
                        ?? \Illuminate\Support\Arr::get($sess->templates, 'assessment');
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
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Fallback by service/treatment slugs
        if (! $form) {
            $serviceFor  = $slugify($metaArr['service_slug'] ?? ($metaArr['service'] ?? null));
            $treatFor    = $slugify($metaArr['treatment_slug'] ?? ($metaArr['treatment'] ?? null));
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

        // Decode schema and normalise to sections
        $sections = [];
        if ($form) {
            $raw = is_array($form->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
            if (is_array($raw) && !empty($raw)) {
                if (array_key_exists('fields', $raw[0] ?? [])) {
                    $sections = $raw;
                } else {
                    $current = ['title' => null, 'fields' => []];
                    foreach ($raw as $blk) {
                        $type = $blk['type'] ?? null;
                        $data = (array) ($blk['data'] ?? []);
                        if ($type === 'section') {
                            if (!empty($current['fields'])) $sections[] = $current;
                            $current = ['title' => $data['label'] ?? ($data['title'] ?? null), 'fields' => []];
                        } else {
                            $field = ['type' => $type];
                            foreach (['label','key','required','options','content','accept','multiple'] as $k) {
                                if (array_key_exists($k, $data)) $field[$k] = $data[$k];
                            }
                            $current['fields'][] = $field;
                        }
                    }
                    if (!empty($current['fields'])) $sections[] = $current;
                }
            }
        }

        // Build maps  key/slug(key)/slug(label)  -> label  and detect file-like fields
        foreach ($sections as $secTmp) {
            foreach (($secTmp['fields'] ?? []) as $f) {
                $type  = $f['type'] ?? 'text_input';
                $label = $f['label'] ?? null;
                $key   = $f['key'] ?? ($label ? $slugify($label) : null);

                if ($key || $label) {
                    if ($key) { $labelMap[$key] = $label ?? $key; $labelMap[$slugify($key)] = $label ?? $key; }
                    if ($label) { $labelMap[$slugify($label)] = $label; }
                }

                if (in_array($type, ['file','file_upload','image','signature'], true)) {
                    if ($key) { $fileFieldKeys[$key] = true; $fileFieldKeys[$slugify($key)] = true; }
                    if ($label) { $fileFieldKeys[$slugify($label)] = true; }
                }
            }
        }

        // Build an option value -> label map for select-like fields
        $optionMap = [];
        foreach ($sections as $secTmp) {
            foreach (($secTmp['fields'] ?? []) as $f) {
                $key  = $f['key'] ?? null;
                $opts = $f['options'] ?? [];
                if ($key && is_array($opts) && !empty($opts)) {
                    $omap = [];
                    foreach ($opts as $o) {
                        if (is_array($o)) {
                            $v = $o['value'] ?? null;
                            $l = $o['label'] ?? $v;
                            if ($v !== null) { $omap[(string) $v] = (string) $l; }
                        }
                    }
                    if (!empty($omap)) {
                        $optionMap[$key] = $omap;
                        $optionMap[\Illuminate\Support\Str::slug($key)] = $omap;
                    }
                }
            }
        }

        // Helper to map option codes to human labels
        $mapOption = function ($key, $val) use ($optionMap) {
            $k  = (string) $key;
            $ks = \Illuminate\Support\Str::slug($k);
            $map = $optionMap[$k] ?? $optionMap[$ks] ?? null;
            if (!$map) return $val;

            if (is_scalar($val)) {
                $sv = (string) $val;
                return array_key_exists($sv, $map) ? $map[$sv] : $val;
            }
            if (is_array($val) && \Illuminate\Support\Arr::isAssoc($val)) {
                if (isset($val['label'])) return $val['label'];
                if (isset($val['value']) && array_key_exists((string) $val['value'], $map)) return $map[(string) $val['value']];
                return $val;
            }
            if (is_array($val)) {
                $out = [];
                foreach ($val as $v) {
                    if (is_scalar($v)) { $sv = (string) $v; $out[] = $map[$sv] ?? $sv; }
                    elseif (is_array($v)) {
                        $lbl = $v['label'] ?? null;
                        $vv  = isset($v['value']) ? (string) $v['value'] : null;
                        $out[] = $lbl ?? ($vv !== null ? ($map[$vv] ?? $vv) : json_encode($v));
                    }
                }
                return $out;
            }
            return $val;
        };
        // Also index options by sequential q_N order so answers saved as q_* map correctly
        $__qIdx = 0;
        $__inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];
        foreach ($sections as $secTmp) {
            foreach (($secTmp['fields'] ?? []) as $f) {
                $t = $f['type'] ?? '';
                if (!in_array($t, $__inputTypes, true)) continue;
                $opts = $f['options'] ?? [];
                if (is_array($opts) && !empty($opts)) {
                    $omap = [];
                    foreach ($opts as $o) {
                        if (is_array($o)) {
                            $v = $o['value'] ?? null;
                            $l = $o['label'] ?? $v;
                            if ($v !== null) { $omap[(string) $v] = (string) $l; }
                        }
                    }
                    if (!empty($omap)) {
                        $qk = 'q_' . $__qIdx;
                        $optionMap[$qk] = $omap;
                        $optionMap[\Illuminate\Support\Str::slug($qk)] = $omap;
                    }
                }
                $__qIdx++;
            }
        }

        // Bridge q_N keys to real labels from the RAF schema
        $qIndexLabels = [];
        $__idx = 0;
        $__inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];

        foreach ($sections as $secTmp) {
            foreach (($secTmp['fields'] ?? []) as $f) {
                $t = $f['type'] ?? '';
                if (!in_array($t, $__inputTypes, true)) continue;

                $lab = $f['label'] ?? null;
                $key = $f['key'] ?? ($lab ? $slugify($lab) : null);
                $qk  = 'q_' . $__idx;

                if ($lab) {
                    $labelMap[$qk] = $lab;
                    $labelMap[$slugify($qk)] = $lab;
                } elseif ($key) {
                    $labelMap[$qk] = $key;
                    $labelMap[$slugify($qk)] = $key;
                }

                $__idx++;
            }
        }

        // Build an order index from the schema and reorder answers to match form order
        $orderIndex = [];
        $ord = 0;
        foreach ($sections as $secO) {
            foreach (($secO['fields'] ?? []) as $fO) {
                $kO = $fO['key'] ?? (isset($fO['label']) ? $slugify($fO['label']) : null);
                if ($kO === null) { $ord++; continue; }
                $orderIndex[$kO] = $ord;
                $orderIndex[$slugify($kO)] = $ord;
                if (isset($fO['label'])) {
                    $orderIndex[$slugify($fO['label'])] = $ord;
                }
                $ord++;
            }
        }
        if (is_array($rafAnswers) && !empty($rafAnswers)) {
            uksort($rafAnswers, function($a, $b) use ($orderIndex, $slugify) {
                $ia = $orderIndex[$a] ?? $orderIndex[$slugify($a)] ?? PHP_INT_MAX - 1;
                $ib = $orderIndex[$b] ?? $orderIndex[$slugify($b)] ?? PHP_INT_MAX - 1;
                return $ia <=> $ib;
            });
        }

        // --- Collect possible uploaded image sources from meta and session meta ---
        $collectUploads = function($arr) use (&$collectUploads) {
            $map = [];
            if (!is_array($arr)) return $map;

            $push = function($name, $src) use (&$map) {
                if (!is_string($name) || $name === '') return;
                if (!is_string($src) || $src === '') return;
                $map[strtolower(basename($name))] = $src;
            };

            $it = function($v) use (&$map, &$push, &$collectUploads) {
                if (is_array($v)) {
                    // object-like with name and a source field
                    if (isset($v['name'])) {
                        $src = $v['url'] ?? $v['src'] ?? $v['path'] ?? (isset($v['p']) ? '/api/uploads/view?p='.$v['p'] : null);
                        if ($src) $push($v['name'], $src);
                    }
                    // recurse
                    foreach ($v as $vv) { if (is_array($vv)) { $sub = $collectUploads($vv); foreach ($sub as $k => $s) { $map[$k] = $s; } } }
                } elseif (is_string($v)) {
                    // plain path or url  map by basename
                    if (preg_match('~(?:/|^)[^/]+\.(?:jpe?g|png|gif|webp|bmp|svg)$~i', $v)) {
                        $push(basename($v), $v);
                    }
                }
            };

            foreach ($arr as $k => $v) { $it($v); }
            return $map;
        };

        $uploadMap = $collectUploads($metaArr);

        if (!empty($sid)) {
            try {
                $sess = $sess ?? \App\Models\ConsultationSession::find($sid);
                if ($sess) {
                    $sessMeta = $toArray($sess->meta ?? []);
                    $uploadMap = array_replace($uploadMap, $collectUploads($sessMeta));
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Normalise values for display
        $human = function ($v) {
            if (is_bool($v)) return $v ? 'Yes' : 'No';
            if ($v === 1 || $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') return 'Yes';
            if ($v === 0 || $v === '0' || $v === 'false' || $v === 'no'  || $v === 'off') return 'No';
            if (is_array($v)) {
                $parts = [];
                foreach ($v as $x) {
                    if (is_scalar($x)) {
                        $parts[] = (string) $x;
                    } elseif (is_array($x)) {
                        $parts[] = $x['label'] ?? $x['value'] ?? $x['text'] ?? $x['name'] ?? null;
                    }
                }
                $parts = array_values(array_filter($parts, fn($s) => is_string($s) ? trim($s) !== '' : (bool)$s));
                return implode(', ', $parts);
            }
            if ($v === null) return '';
            $s = trim((string) $v);
            return $s;
        };

        $labelise = function ($k) {
            $k = str_replace(['_', '-'], ' ', (string) $k);
            $k = preg_replace('/\s+/', ' ', trim($k));
            $k = ucwords($k);
            return $k;
        };

        // Map option codes to human labels using the schema's options
        $mapOption = function ($key, $val) use ($optionMap, $slugify) {
            $k = (string) $key;
            $ks = $slugify($k);
            $map = $optionMap[$k] ?? $optionMap[$ks] ?? null;
            if (!$map) return $val;

            // scalar
            if (is_scalar($val)) {
                $sv = (string) $val;
                return array_key_exists($sv, $map) ? $map[$sv] : $val;
            }
            // assoc with value/label
            if (is_array($val) && \Illuminate\Support\Arr::isAssoc($val)) {
                if (isset($val['label'])) return $val['label'];
                if (isset($val['value']) && array_key_exists((string) $val['value'], $map)) return $map[(string) $val['value']];
                return $val;
            }
            // list
            if (is_array($val)) {
                $out = [];
                foreach ($val as $v) {
                    if (is_scalar($v)) { $sv = (string) $v; $out[] = $map[$sv] ?? $sv; }
                    elseif (is_array($v)) {
                        $lbl = $v['label'] ?? null;
                        $vv  = isset($v['value']) ? (string) $v['value'] : null;
                        $out[] = $lbl ?? ($vv !== null ? ($map[$vv] ?? $vv) : json_encode($v));
                    }
                }
                return $out;
            }
            return $val;
        };

        // Flatten nested associative answers so inner real field keys are lifted to top-level
        $flattenBySchema = function ($answers) {
            if (!is_array($answers)) return [];

            $isFileLike = function ($v) {
                if (!is_array($v)) return false;
                if (!\Illuminate\Support\Arr::isAssoc($v)) {
                    $first = $v[0] ?? null;
                    return is_array($first) && (isset($first['name']) || isset($first['url']) || isset($first['path']) || isset($first['src']) || isset($first['data']) || isset($first['dataUrl']) || isset($first['data_url']));
                }
                return isset($v['name']) || isset($v['url']) || isset($v['path']) || isset($v['src']) || isset($v['data']) || isset($v['dataUrl']) || isset($v['data_url']);
            };

            $out = [];
            foreach ($answers as $k => $v) {
                if (is_array($v) && \Illuminate\Support\Arr::isAssoc($v) && !$isFileLike($v)) {
                    foreach ($v as $ik => $iv) {
                        $out[$ik] = $iv;
                    }
                    if (array_key_exists('raw', $v) || array_key_exists('answer', $v) || array_key_exists('value', $v)) {
                        $out[$k] = $v['raw'] ?? $v['answer'] ?? $v['value'];
                    }
                    continue;
                }
                $out[$k] = $v;
            }
            return $out;
        };

        // Ignore internal transport keys
        $__ignoreKeys = ['_token','__go_next','__step_slug','tab','step','form','service_slug','treatment_slug'];

        // Build section lookups from RAF schema to support sectioned rendering
        $sectionsByKey = [];
        $sectionsByIdx = [];
        $sectionsByLabel = [];
        $__inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];

        $__secIdx = 0;
        foreach ($sections as $secTmp) {
            $secTitle = $secTmp['title'] ?? null;
            foreach (($secTmp['fields'] ?? []) as $f) {
                $t = $f['type'] ?? '';
                if (!in_array($t, $__inputTypes, true)) continue;

                $key = $f['key'] ?? null;
                $lab = $f['label'] ?? null;

                if ($key) {
                    $sectionsByKey[$key] = $secTitle;
                    $sectionsByKey[\Illuminate\Support\Str::slug($key)] = $secTitle;
                }
                if ($lab) {
                    $sectionsByLabel[\Illuminate\Support\Str::of($lab)->lower()->squish()->toString()] = $secTitle;
                }

                $sectionsByIdx['q_' . $__secIdx] = $secTitle;
                $__secIdx++;
            }
        }
    @endphp

    <div class="section-title">Clinical Assessment</div>
@php
    // Schema-first rendering  show all questions even if unanswered
    // 1) Flatten nested answers so inner field keys render individually
    $rafAnswers = $flattenBySchema($rafAnswers);

    // 2) Build a fast lookup of answers
    $answers = is_array($rafAnswers) ? $rafAnswers : [];

    // 3) Build q_N index mapping from schema order
    $__inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];
    $__q = 0;
    $qKeyByIndex = [];
    foreach ($sections as $secTmp) {
        foreach (($secTmp['fields'] ?? []) as $f) {
            $t = $f['type'] ?? '';
            if (!in_array($t, $__inputTypes, true)) continue;
            $key = $f['key'] ?? null;
            $lab = $f['label'] ?? null;
            $qKeyByIndex['q_' . $__q] = [$key, $lab];
            $__q++;
        }
    }

    // 4) Helper  fetch the best-matching answer for a field
    $getAnswer = function ($key, $label, $idx) use ($answers, $slugify) {
        $cands = [];
        if ($key) { $cands[] = $key; $cands[] = $slugify($key); }
        if ($label) { $cands[] = $label; $cands[] = $slugify($label); }
        if ($idx !== null) { $cands[] = 'q_' . $idx; }
        foreach ($cands as $ck) {
            if (array_key_exists($ck, $answers)) return $answers[$ck];
        }
        return null;
    };

    // 5) Build grouped rows by schema sections, ensuring every question appears at least once
    $grouped = [];
    $sectionOrder = [];
    $__globalIdx = 0;

    foreach ($sections as $secTmp) {
        $secTitle = $secTmp['title'] ?? 'General';
        if (!isset($grouped[$secTitle])) {
            $grouped[$secTitle] = [];
            $sectionOrder[] = $secTitle;
        }

        foreach (($secTmp['fields'] ?? []) as $f) {
            $t = $f['type'] ?? '';
            if (!in_array($t, $__inputTypes, true)) continue;

            $lab = $f['label'] ?? ($f['key'] ?? 'Question');
            $key = $f['key'] ?? ($lab ? $slugify($lab) : null);

            // Fetch the captured answer if any
            $ans = $getAnswer($key, $lab, $__globalIdx);

            // Fuzzy catch for file/image answers saved under different keys
            if ($ans === null) {
                $labSlug = $slugify($lab ?? ($key ?? ''));
                foreach ($answers as $ak => $av) {
                    $aks = $slugify($ak);
                    // if the answer key contains the label/key slug, or is a generic file/image key, use it
                    if (
                        ($labSlug !== '' && str_contains($aks, $labSlug)) ||
                        preg_match('/\b(image|photo|upload|file|attachment|record|evidence)\b/i', (string) $ak)
                    ) {
                        $ans = $av;
                        break;
                    }
                }
            }

            // Normalise value
            $val = $ans;
            if (is_array($val) && \Illuminate\Support\Arr::isAssoc($val)) {
                $val = $val['raw'] ?? $val['answer'] ?? $val['value'] ?? $val['label'] ?? $val['text'] ?? $val['name'] ?? $val;
            }

            // Map select-like codes to human labels using schema options
            $val = $mapOption($key ?? ('q_' . $__globalIdx), $val);

            // Produce human readable output
            $display = $human($val);

            // If file-like input and we have a value, prefer showing a filename
            if (in_array($t, ['file','file_upload','image','signature'], true) && $ans !== null) {
                $fileName = null;
                if (is_string($ans)) {
                    $fileName = basename($ans);
                } elseif (is_array($ans)) {
                    $list = \Illuminate\Support\Arr::isAssoc($ans) ? [$ans] : $ans;
                    foreach ($list as $__v) {
                        if (is_array($__v) && isset($__v['name'])) { $fileName = (string) $__v['name']; break; }
                        if (is_array($__v) && (isset($__v['url']) || isset($__v['path']) || isset($__v['src']))) { $fileName = basename((string) ($__v['url'] ?? $__v['path'] ?? $__v['src'])); break; }
                        if (is_string($__v) && strpos($__v, '.') !== false) { $fileName = basename($__v); break; }
                    }
                }
                if ($fileName) $display = $fileName;
            }

            // Ensure every question shows a value even if unanswered
            if ($display === '' || $display === null) {
                $display = 'No response provided';
            }

            $grouped[$secTitle][] = [$lab, $display];
            $__globalIdx++;
        }
    }

    // 6) If schema could not be resolved, fall back to answer-based view but still fill blanks
    if (empty($sectionOrder) && !empty($answers)) {
        $sectionOrder = ['General'];
        $grouped['General'] = [];
        foreach ($answers as $q => $a) {
            if (in_array((string) $q, $__ignoreKeys, true) || str_starts_with((string) $q, '__')) continue;
            $label = $labelMap[$q] ?? $labelMap[$slugify($q)] ?? $labelise($q);

            $val = is_array($a) && isset($a['label']) ? $a['label'] : (is_array($a) && isset($a['value']) ? $a['value'] : $a);
            $val = $mapOption($q, $val);
            $disp = $human($val);
            if ($disp === '' || $disp === null) $disp = 'No response provided';

            $grouped['General'][] = [$label, $disp];
        }
    }
@endphp

@foreach($sectionOrder as $__secTitle)
  <div class="section-title" style="margin-top:10px;">{{ $__secTitle }}</div>
  <table class="items">
    <thead>
      <tr>
        <th style="width:55%">Question</th>
        <th>Answer</th>
      </tr>
    </thead>
    <tbody>
      @foreach($grouped[$__secTitle] as $__row)
        <tr>
          <td>{{ $__row[0] }}</td>
          <td>{{ $__row[1] }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endforeach
@php
    // Uploaded Images only
    $apiBase = config('services.pharmacy_api.base') ?? env('API_BASE') ?? env('NEXT_PUBLIC_API_BASE') ?? 'http://localhost:8000';
    $rafImages = [];

    // build a quick index of uploaded files in meta and session meta
    $collectUploads = function ($arr) use (&$collectUploads) {
        $map = [];
        if (!is_array($arr)) return $map;

        $push = function ($name, $src) use (&$map) {
            if (!is_string($name) || $name === '') return;
            if (!is_string($src)  || $src  === '') return;
            $map[strtolower(basename($name))] = $src;
        };

        $iter = function ($v) use (&$collectUploads, &$push) {
            if (is_array($v)) {
                if (isset($v['name'])) {
                    $src = $v['data'] ?? $v['dataUrl'] ?? $v['data_url'] ?? $v['url'] ?? $v['src'] ?? $v['path'] ?? (isset($v['p']) ? '/api/uploads/view?p='.$v['p'] : '');
                    if ($src) $push($v['name'], $src);
                }
                foreach ($v as $vv) { if (is_array($vv)) foreach ($collectUploads($vv) as $k => $s) $map[$k] = $s; }
            } elseif (is_string($v)) {
                if (preg_match('~(?:/|^)[^/]+\.(?:jpe?g|png|gif|webp|bmp)$~i', $v)) $push(basename($v), $v);
            }
        };

        foreach ($arr as $vv) $iter($vv);
        return $map;
    };

    $uploadMap = $collectUploads($metaArr);
    if (!empty($sid)) {
        try {
            $sess = $sess ?? \App\Models\ConsultationSession::find($sid);
            if ($sess) {
                $sessMeta = $toArray($sess->meta ?? []);
                $uploadMap = array_replace($uploadMap, $collectUploads($sessMeta));
            }
        } catch (\Throwable) {}
    }

    // find candidate sources from an answer value and also via filename lookup
    $extractCandidates = function ($val) use ($uploadMap) {
        $out = [];
        $push = function ($s) use (&$out) { if (is_string($s) && $s !== '') $out[] = $s; };

        if (is_string($val)) {
            $push($val);
            // if it is only a bare filename, try lookup
            if (strpos($val, '/') === false && strpos($val, '\\') === false) {
                $fname = strtolower(basename($val));
                if (isset($uploadMap[$fname])) $push($uploadMap[$fname]);
            }
            return $out;
        }

        if (is_array($val)) {
            $list = \Illuminate\Support\Arr::isAssoc($val) ? [$val] : $val;
            foreach ($list as $v) {
                if (is_string($v)) {
                    $push($v);
                    if (strpos($v, '/') === false && strpos($v, '\\') === false) {
                        $fname = strtolower(basename($v));
                        if (isset($uploadMap[$fname])) $push($uploadMap[$fname]);
                    }
                    continue;
                }
                if (!is_array($v)) continue;

                $src = $v['data'] ?? $v['dataUrl'] ?? $v['data_url'] ?? $v['url'] ?? $v['src'] ?? $v['path'] ?? '';
                if ($src !== '') $push($src);

                if ($src === '' && isset($v['name'])) {
                    $fname = strtolower(basename((string) $v['name']));
                    if (isset($uploadMap[$fname])) $push($uploadMap[$fname]);
                }
                if ($src === '' && isset($v['p'])) {
                    $push('/api/uploads/view?p='.$v['p']);
                }
            }
        }
        return $out;
    };

    // convert any preview or path into data image for DOMPDF
    $toDataUrl = function ($src) use ($apiBase) {
        if (!is_string($src) || $src === '') return '';
        if (str_starts_with($src, 'data:image/')) return $src;

        // Build an absolute preview URL against the API base, not app.url
        $url = null;
        if (preg_match('~^https?://~i', $src)) {
            $url = $src;
        } elseif (str_starts_with($src, '/api/uploads/view')) {
            $url = rtrim($apiBase, '/') . $src;
        } elseif (str_starts_with($src, 'api/uploads/view')) {
            $url = rtrim($apiBase, '/') . '/' . $src;
        }

        if ($url) {
            try {
                $res = \Illuminate\Support\Facades\Http::timeout(5)->get($url);
                if ($res->ok()) {
                    $mime = $res->header('Content-Type') ?: 'image/jpeg';
                    $body = $res->body();
                    if (is_string($body) && strlen($body) > 0) {
                        return 'data:' . $mime . ';base64,' . base64_encode($body);
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fall through
            }
        }

        return '';
    };

    // walk file fields in schema order and collect renderable images
    $__fileTypes = ['file','file_upload','image','signature'];
    $__idx2 = 0;
    foreach ($sections as $secTmp) {
        foreach (($secTmp['fields'] ?? []) as $f) {
            $t = $f['type'] ?? '';
            if (!in_array($t, $__fileTypes, true)) { $__idx2++; continue; }

            $lab = $f['label'] ?? ($f['key'] ?? 'Attachment');
            $key = $f['key'] ?? ($lab ? $slugify($lab) : null);

            $ans = $getAnswer($key, $lab, $__idx2);
            $__idx2++;

            if ($ans === null) continue;

            $cands = $extractCandidates($ans);

            // last resort try filename directly from label key if the answer was just a name string saved elsewhere
            if (empty($cands)) {
                $guessNames = [];
                if (is_string($ans)) $guessNames[] = $ans;
                if (is_array($ans) && isset($ans['name'])) $guessNames[] = (string) $ans['name'];
                foreach ($guessNames as $gn) {
                    $fn = strtolower(basename($gn));
                    if (isset($uploadMap[$fn])) $cands[] = $uploadMap[$fn];
                }
            }

            $dataUrl = '';
            $remoteSrc = '';
            foreach ($cands as $c) {
                $dataUrl = $toDataUrl($c);
                if ($dataUrl !== '') break;
                if ($remoteSrc === '' && preg_match('~^https?://|^/api/uploads/view|^api/uploads/view~i', $c)) {
                    $remoteSrc = $c;
                    if (str_starts_with($remoteSrc, '/api/uploads/view')) {
                        $remoteSrc = rtrim($apiBase, '/') . $remoteSrc;
                    } elseif (str_starts_with($remoteSrc, 'api/uploads/view')) {
                        $remoteSrc = rtrim($apiBase, '/') . '/' . $remoteSrc;
                    }
                }
            }

            if ($dataUrl !== '') {
                $rafImages[$lab] = $dataUrl;
            } elseif ($remoteSrc !== '') {
                $rafImages[$lab] = $remoteSrc;
            }
        }
    }

    // Fallback  also scan raw answers for any image-like values when schema or keys don't match
    if (empty($rafImages) && isset($answers) && is_array($answers)) {
        foreach ($answers as $ansKey => $ansVal) {
            // build label from known maps or key
            $lbl = $labelMap[$ansKey] ?? $labelMap[$slugify($ansKey)] ?? $labelise($ansKey);

            $cands = $extractCandidates($ansVal);
            if (empty($cands)) continue;

            $dataUrl = '';
            $remoteSrc = '';
            foreach ($cands as $c) {
                $dataUrl = $toDataUrl($c);
                if ($dataUrl !== '') break;
                if ($remoteSrc === '' && preg_match('~^https?://|^/api/uploads/view|^api/uploads/view~i', $c)) {
                    $remoteSrc = $c;
                    if (str_starts_with($remoteSrc, '/api/uploads/view')) {
                        $remoteSrc = rtrim($apiBase, '/') . $remoteSrc;
                    } elseif (str_starts_with($remoteSrc, 'api/uploads/view')) {
                        $remoteSrc = rtrim($apiBase, '/') . '/' . $remoteSrc;
                    }
                }
            }
            if ($dataUrl !== '') {
                $rafImages[$lbl] = $dataUrl;
            } elseif ($remoteSrc !== '') {
                $rafImages[$lbl] = $remoteSrc;
            }
        }
    }
@endphp

@if(!empty($rafImages))
  <div style="margin-top:18px;">
    <div class="section-title">Uploaded Images</div>
    <table class="items">
      <tbody>
        @foreach($rafImages as $label => $dataUrl)
          <tr>
            <td style="width:35%; vertical-align:top;">{{ $label }}</td>
            <td>
              <img src="{{ $dataUrl }}" style="max-width:360px; max-height:240px; border:1px solid #d0d0d0; border-radius:6px; padding:2px;">
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
@endif
    @if(empty($sectionOrder) && empty($rafImages))
      <div class="muted">No risk assessment answers were found for this consultation.</div>
    @endif
  </div>

</body>
</html>