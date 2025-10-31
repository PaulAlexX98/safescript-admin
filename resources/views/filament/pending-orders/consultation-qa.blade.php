@php
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\Http;

    // 1) Load meta and answers from the order
    $meta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
    $formsQA = data_get($meta, 'formsQA') ?: data_get($meta, 'consultation.formsQA', []);
    $qaKeyUsed = null;
    if (!empty($tmp = data_get($formsQA, 'assessment.qa', []))) {
        $qa = array_values($tmp);
        $qaKeyUsed = 'assessment';
    } elseif (!empty($tmp = data_get($formsQA, 'risk_assessment.qa', []))) {
        $qa = array_values($tmp);
        $qaKeyUsed = 'risk_assessment';
    } else {
        $qa = array_values(data_get($formsQA, 'raf.qa', []) ?: []);
        $qaKeyUsed = 'raf';
    }

    // 2) Fetch the exact RAF schema from API using service slug
    $serviceSlug = data_get($meta, 'service_slug') ?: data_get($meta, 'consultation.service_slug');
    $apiBase = config('services.pharmacy_api.base')
        ?? env('API_BASE')
        ?? env('NEXT_PUBLIC_API_BASE')
        ?? 'http://localhost:8000';

    $rafSchema = [];
    $assessmentSchema = [];
    $activeSchema = [];
    if ($serviceSlug) {
        try {
            $resp = Http::timeout(3)
                ->acceptJson()
                ->get(rtrim($apiBase, '/')."/api/services/{$serviceSlug}/forms");

            if ($resp->ok()) {
                $json = $resp->json();

                $rafSchema = data_get($json, 'raf_form.schema') ?? data_get($json, 'raf.schema') ?? [];
                if (is_string($rafSchema)) {
                    $rafSchema = json_decode($rafSchema, true) ?: [];
                }

                $assessmentSchema = data_get($json, 'assessment_form.schema')
                                  ?? data_get($json, 'assessment.schema')
                                  ?? data_get($json, 'risk_assessment_form.schema')
                                  ?? data_get($json, 'risk_assessment.schema')
                                  ?? [];
                if (is_string($assessmentSchema)) {
                    $assessmentSchema = json_decode($assessmentSchema, true) ?: [];
                }
            }
        } catch (\Throwable $e) {
            $rafSchema = [];
            $assessmentSchema = [];
        }
    }

    // Fallbacks from stored meta if API returned none
    if (empty($assessmentSchema)) {
        $tmp = data_get($formsQA, 'assessment.schema')
            ?? data_get($formsQA, 'risk_assessment.schema')
            ?? null;
        if (is_string($tmp)) {
            $tmp = json_decode($tmp, true) ?: [];
        }
        if (is_array($tmp)) {
            $assessmentSchema = $tmp;
        }
    }

    if (empty($rafSchema)) {
        $tmp = data_get($formsQA, 'raf.schema') ?? null;
        if (is_string($tmp)) {
            $tmp = json_decode($tmp, true) ?: [];
        }
        if (is_array($tmp)) {
            $rafSchema = $tmp;
        }
    }

    $activeSchema = in_array($qaKeyUsed, ['assessment', 'risk_assessment'], true)
        ? ($assessmentSchema ?: $rafSchema)
        : $rafSchema;

    // 3) Flatten ONLY real input fields (skip containers) and preserve order
    $inputNodes = [];

    $containerTypes = [
        'section','group','row','column','columns','grid','card','fieldset','legend','container',
        'heading','title','subtitle','paragraph','description','html','info','note','div'
    ];
    $inputTypes = [
        'select','text','textarea','date','datepicker','radio','checkbox','switch','toggle',
        'file','image','upload','multi-select','multiselect','email','number','tel','url','country','time','datetime','yesno'
    ];

    $currentHeading = null;

    $walk = function ($node, $section = null) use (&$walk, &$inputNodes, $containerTypes, $inputTypes, &$currentHeading) {
        if ($node instanceof \stdClass) $node = (array) $node;
        if (! is_array($node)) return;

        $type = strtolower((string) ((\Illuminate\Support\Arr::get($node, 'type', '') ?? '')));

        // Section marker block produced by ClinicFormForm builder: set heading and let subsequent siblings inherit
        if ($type === 'section') {
            $h = \Illuminate\Support\Arr::get($node, 'data.label')
               ?? \Illuminate\Support\Arr::get($node, 'label')
               ?? \Illuminate\Support\Arr::get($node, 'data.heading')
               ?? null;
            if (is_string($h) && trim($h) !== '') {
                $currentHeading = trim($h);
            }
            // If this section block actually contains children, walk them with this section applied
            foreach (['schema','components','fields','children'] as $childrenKey) {
                $maybe = $node[$childrenKey] ?? null;
                if (is_array($maybe)) {
                    foreach ($maybe as $child) {
                        if (is_array($child) || $child instanceof \stdClass) $walk($child, $currentHeading);
                    }
                }
            }
            return;
        }

        // Detect standalone heading-like nodes and remember for subsequent siblings
        if (in_array($type, ['heading','title','subtitle','legend'], true)) {
            $h = \Illuminate\Support\Arr::get($node, 'data.text')
               ?? \Illuminate\Support\Arr::get($node, 'data.label')
               ?? \Illuminate\Support\Arr::get($node, 'label');
            if (is_string($h) && trim($h) !== '') {
                $currentHeading = trim($h);
            }
            return; // headings themselves are not inputs
        }

        // If container, derive a section label if present and recurse
        if (in_array($type, $containerTypes, true)) {
            $secLabel = \Illuminate\Support\Arr::get($node, 'data.heading')
                     ?? \Illuminate\Support\Arr::get($node, 'data.label')
                     ?? \Illuminate\Support\Arr::get($node, 'label');
            $childSection = is_string($secLabel) && trim($secLabel) !== ''
                ? trim($secLabel)
                : ($section ?: $currentHeading);

            foreach ($node as $child) {
                if (is_array($child) || $child instanceof \stdClass) $walk($child, $childSection);
            }
            return;
        }

        $key   = \Illuminate\Support\Arr::get($node, 'name') ?? \Illuminate\Support\Arr::get($node, 'key');
        $label = \Illuminate\Support\Arr::get($node, 'data.label') ?? \Illuminate\Support\Arr::get($node, 'label');
        $opts  = \Illuminate\Support\Arr::get($node, 'data.options') ?? \Illuminate\Support\Arr::get($node, 'options');

        $looksInput = in_array($type, $inputTypes, true) || ($key && ($label !== null || is_array($opts)));
        if ($looksInput) {
            $explicitSection = \Illuminate\Support\Arr::get($node, 'data.section')
                             ?? \Illuminate\Support\Arr::get($node, 'section');
            $secToUse = (is_string($explicitSection) && trim($explicitSection) !== '')
                ? trim($explicitSection)
                : ($section ?: $currentHeading);

            $inputNodes[] = [
                'key' => $key ? (string) $key : null,
                'label' => $label,
                'options' => is_array($opts) ? $opts : [],
                'section' => $secToUse,
            ];
        }

        foreach ($node as $child) {
            if (is_array($child) || $child instanceof \stdClass) $walk($child, $section ?: $currentHeading);
        }
    };

    $walk($activeSchema);

    // 4) Build maps: by explicit key and by sequential index (q_N)
    $labelsByKey = [];
    $labelsByIdx = []; // e.g. q_0 => label
    $optionsByKey = [];
    $optionsByIdx = [];
    $sectionsByKey = [];
    $sectionsByIdx = [];

    foreach ($inputNodes as $idx => $field) {
        $k = $field['key'];
        $lbl = $field['label'] ?? null;
        $opt = $field['options'] ?? [];

        if ($lbl !== null) {
            $labelsByIdx['q_' . $idx] = $lbl;
            if ($k) $labelsByKey[$k] = $lbl;
        }

        if (!empty($opt)) {
            $mapped = collect($opt)->mapWithKeys(function ($o) {
                $val = is_array($o) ? ($o['value'] ?? null) : null;
                $lab = is_array($o) ? ($o['label'] ?? $val) : $val;
                return $val !== null ? [(string) $val => (string) $lab] : [];
            })->all();
            $optionsByIdx['q_' . $idx] = $mapped;
            if ($k) $optionsByKey[$k] = $mapped;
        }

        $sec = $field['section'] ?? null;
        if (is_string($sec) && $sec !== '') {
            $sectionsByIdx['q_' . $idx] = $sec;
            if (!empty($field['key'])) {
                $sectionsByKey[$field['key']] = $sec;
            }
        }
    }

    // Merge lookups for convenience
    $labels = $labelsByIdx + $labelsByKey; // index labels, overridden by explicit keys
    $options = $optionsByIdx + $optionsByKey;
    $sections = ($sectionsByIdx ?? []) + ($sectionsByKey ?? []);

    // Build a fallback lookup from field label -> section, in case QA rows don't carry keys
    $sectionsByLabel = [];
    foreach ($inputNodes as $field) {
        $lbl = $field['label'] ?? null;
        $sec = $field['section'] ?? null;
        if (is_string($lbl) && $lbl !== '' && is_string($sec) && $sec !== '') {
            $norm = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($lbl))));
            if ($norm !== '' && !isset($sectionsByLabel[$norm])) {
                $sectionsByLabel[$norm] = $sec;
            }
        }
    }

    // Precompute groups for assessment-style rendering to avoid Tailwind dependence
    $isAssessment = in_array($qaKeyUsed, ['assessment', 'risk_assessment'], true);

    $grouped = [];
    $sectionOrder = [];

    if ($isAssessment) {
        foreach (array_values($qa) as $idx => $row) {
            $key = $row['key'] ?? ($row['question'] ?? 'q_'.$idx);

            // Resolve label similar to render loop
            if (isset($labels[$key])) {
                $label = $labels[$key];
            } elseif (preg_match('/^q_(\d+)$/', (string) $key, $m) && isset($labels['q_'.$m[1]])) {
                $label = $labels['q_'.$m[1]];
            } else {
                $savedQ = (string) ($row['question'] ?? '');
                $label = preg_match('/^Question\s+\d+$/i', $savedQ)
                    ? ucwords(str_replace(['_', '-'], ' ', (string) $key))
                    : ($savedQ ?: ucwords(str_replace(['_', '-'], ' ', (string) $key)));
            }

            // Prefer raw then fallback to answer
            $answer = array_key_exists('raw', $row) && $row['raw'] !== null && $row['raw'] !== ''
                ? $row['raw']
                : ($row['answer'] ?? null);

            // Determine section name by key then by q_N, then by label text fallback
            $sectionName = null;
            if (isset($sections[$key])) {
                $sectionName = $sections[$key];
            } elseif (preg_match('/^q_(\d+)$/', (string) $key, $m) && isset($sections['q_'.$m[1]])) {
                $sectionName = $sections['q_'.$m[1]];
            } else {
                $norm = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags((string) $label))));
                if ($norm !== '' && isset($sectionsByLabel[$norm])) {
                    $sectionName = $sectionsByLabel[$norm];
                }
            }
            // Heuristic grouping for travel-clinic style assessments when schema sections are unavailable
            if (!$sectionName && isset($label)) {
                $ll = strtolower(strip_tags((string) $label));
                if (str_contains($ll, 'trip') || str_contains($ll, 'country')) {
                    $sectionName = 'Trip details';
                } elseif (str_contains($ll, 'past medical')) {
                    $sectionName = 'Medical history';
                } elseif (
                    str_contains($ll, 'vaccin') || str_contains($ll, 'antimalarial') || str_contains($ll, 'doxycycline')
                ) {
                    $sectionName = 'Vaccination';
                } elseif (
                    str_contains($ll, 'attach') || str_contains($ll, 'record') || str_contains($ll, 'upload')
                ) {
                    $sectionName = 'Attachments';
                } elseif (
                    str_starts_with($ll, 'are you ') || str_contains($ll, 'medication') || str_contains($ll, 'antibiotic') ||
                    str_contains($ll, 'fever') || str_contains($ll, 'clotting') || str_contains($ll, 'pregnan') || str_contains($ll, 'breast')
                ) {
                    $sectionName = 'Health screening';
                }
            }

            // Default to "General" if we can't resolve
            $sectionTitle = is_string($sectionName) && $sectionName !== '' ? $sectionName : 'General';

            if (!array_key_exists($sectionTitle, $grouped)) {
                $grouped[$sectionTitle] = [];
                $sectionOrder[] = $sectionTitle;
            }

            $grouped[$sectionTitle][] = [
                'key' => $key,
                'label' => $label,
                'answer' => $answer,
            ];
        }
    }

    // If API didn't give QA rows but we found raw assessment answers, normalise them into QA rows
    if (empty($qa) && is_array($assessmentAnswers) && !empty($assessmentAnswers)) {
        $qa = [];
        foreach ($assessmentAnswers as $k => $v) {
            $label = \Illuminate\Support\Str::headline((string) $k);
            $qa[] = ['key' => (string) $k, 'question' => $label, 'answer' => $v];
        }
        $qaKeyUsed = 'assessment';
    }

    // Helper to turn stored file paths into a browser-accessible URL
    // Normalises any of the following into the public preview endpoint:
    //   - absolute filesystem path
    //   - /storage/... URL
    //   - full http://.../storage/... URL
    //   - plain "intakes/raf/xxx.png"
    $makePublicUrl = function ($p) use ($apiBase) {
        if (!is_string($p) || $p === '') return '';

        // If it's an http(s) URL we may still need to rewrite it, e.g.
        //   http://localhost:8000/storage/intakes/raf/abc.png
        // We'll try to extract a relative "intakes/raf/abc.png" from it.
        if (preg_match('/^https?:\\/\\//i', $p)) {
            $rel = null;

            $pathOnly = parse_url($p, PHP_URL_PATH) ?? '';

            // Match /storage/app/public/intakes/raf/abc.png
            if ($rel === null && preg_match('#/storage/app/public/(.*)$#', $pathOnly, $m)) {
                $rel = $m[1];
            }

            // Match /storage/intakes/raf/abc.png
            if ($rel === null && preg_match('#/storage/(.*)$#', $pathOnly, $m)) {
                $rel = $m[1];
            }

            // If we successfully derived a relative path, build the preview endpoint URL.
            if ($rel !== null && $rel !== '') {
                return rtrim($apiBase, '/') . '/api/uploads/view?p=' . rawurlencode($rel);
            }

            // As a fallback, just return the original absolute URL.
            return $p;
        }

        // Not an absolute URL. We now want the RELATIVE bit under storage/app/public.
        // Example raw inputs we might see:
        //   /Applications/.../storage/app/public/intakes/raf/abc.png
        //   /storage/intakes/raf/abc.png
        //   intakes/raf/abc.png
        $rel = null;

        // Full absolute server path containing storage/app/public/...
        if ($rel === null && preg_match('#/storage/app/public/(.*)$#', $p, $m)) {
            $rel = $m[1]; // "intakes/raf/abc.png"
        }

        // Already looks like /storage/intakes/raf/abc.png
        if ($rel === null && str_starts_with($p, '/storage/')) {
            $rel = ltrim(substr($p, strlen('/storage/')), '/');
        }

        // Plain "intakes/raf/abc.png"
        if ($rel === null) {
            $rel = ltrim($p, '/');
        }

        // Final URL hits the pharmacy-api preview endpoint, which streams the file inline.
        return rtrim($apiBase, '/') . '/api/uploads/view?p=' . rawurlencode($rel);
    };

    // Helper to generate an inline base64 preview for local files
    // Falls back to '' if we can't read it
    $makePreviewDataUrl = function ($p) {
        if (!is_string($p) || $p === '') return '';

        // if it's an absolute local path and readable, embed it
        if (str_starts_with($p, '/Applications/') && is_file($p) && is_readable($p)) {
            $mime = @mime_content_type($p) ?: 'image/png';
            $bytes = @file_get_contents($p);
            if ($bytes !== false) {
                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        }

        // otherwise no inline preview
        return '';
    };

    // 5) Pretty printer: decode JSON, show filenames, map options
    $pretty = function ($key, $val) use ($options, $makePublicUrl, $makePreviewDataUrl) {
        $map = $options[$key] ?? [];

        // Decode JSON string values
        if (is_string($val)) {
            $t = trim($val);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $decoded = json_decode($t, true);
                if (json_last_error() === JSON_ERROR_NONE) $val = $decoded;
            }
        }

        // Normalise single assoc to list
        if (is_array($val) && \Illuminate\Support\Arr::isAssoc($val)) {
            $val = [$val];
        }

        if (is_array($val)) {
            // file-like (evidence uploads etc)
            if (
                isset($val[0]) &&
                is_array($val[0]) &&
                (isset($val[0]['name']) || isset($val[0]['url']) || isset($val[0]['path']))
            ) {
                $first = $val[0];

                $name = (string) ($first['name'] ?? basename((string) ($first['url'] ?? $first['path'] ?? 'file')));

                // raw path from API (may be filesystem or relative OR absolute local filesystem path)
                $hrefRaw = (string) ($first['url'] ?? $first['path'] ?? '');

                // browser-facing URL guess (eg http://api.test/storage/...)
                $href = $makePublicUrl($hrefRaw);

                $type = (string) ($first['type'] ?? '');
                $isImage = $type !== '' && str_starts_with(strtolower($type), 'image/');

                // if backend didn't send type, infer from filename
                if (!$isImage && preg_match('/\\.(png|jpe?g|webp|gif)$/i', $name)) {
                    $isImage = true;
                }

                // choose best thumbnail source
                // 1. try to embed local file as base64 (works even if Apache blocks symlinks and 403s)
                // 2. fallback to public URL
                $thumbSrc = '';
                if ($isImage) {
                    $thumbSrc = $makePreviewDataUrl($hrefRaw);
                    if ($thumbSrc === '' && $href !== '') {
                        $thumbSrc = $href;
                    }
                }

                // If we have an image, try to render a thumbnail block
                if ($isImage && $thumbSrc !== '') {
                    // If we also have an href we can open in a new tab, wrap it in <a>
                    if ($href !== '') {
                        return
                            '<a href="' . e($href) . '" class="group inline-flex items-start gap-3" target="_blank" rel="noopener noreferrer">'
                            . '<img src="' . e($thumbSrc) . '" class="h-20 w-20 rounded border border-gray-700 object-cover bg-black/20 group-hover:opacity-90" alt="' . e($name) . ' thumbnail" />'
                            . '<span class="text-blue-400 underline break-all text-xs mt-1 group-hover:text-blue-300">' . e($name) . '</span>'
                            . '</a>';
                    }

                    // no href (403 etc) so just show inline preview + filename
                    return
                        '<div class="inline-flex items-start gap-3">'
                        . '<img src="' . e($thumbSrc) . '" class="h-20 w-20 rounded border border-gray-700 object-cover bg-black/20" alt="' . e($name) . ' thumbnail" />'
                        . '<span class="text-xs text-gray-300 break-all mt-1">' . e($name) . '</span>'
                        . '</div>';
                }

                // Not an image, but we still have a link (eg PDF)
                if ($href !== '') {
                    return '<a href="' . e($href) . '" class="text-blue-400 underline break-all" target="_blank" rel="noopener noreferrer">' . e($name) . '</a>';
                }

                // fallback to plain name if no URL/path
                return e($name);
            }

            // multi-select etc
            $flat = [];
            foreach ($val as $v) {
                $sv = is_scalar($v) ? (string) $v : json_encode($v);
                $flat[] = $map[$sv] ?? $sv;
            }
            return implode(', ', $flat);
        }

        if (is_scalar($val)) {
            $s = (string) $val;

            // If backend stored the file answer as just a string path instead of an array (legacy/edge case),
            // try to render it like an uploaded image/link.
            // Example: "intakes/raf/ZuK8y9aE9ktSIKhJdZ8Wmzcnl09g6p6wnmFvWesg.png"
            $looksLikeImage = preg_match('/\\.(png|jpe?g|webp|gif)$/i', $s);
            $looksLikeEvidencePath = str_contains($s, 'intakes/raf/')
                                   || str_contains($s, '/storage/intakes/raf/')
                                   || str_contains($s, '/storage/app/public/intakes/raf/');

            if ($looksLikeEvidencePath && $looksLikeImage) {
                $name = basename($s);
                $href = $makePublicUrl($s);

                // fallback thumbnail source is just the same href
                $thumbSrc = $href;

                if ($thumbSrc !== '') {
                    return
                        '<a href="' . e($href) . '" class="group inline-flex items-start gap-3" target="_blank" rel="noopener noreferrer">'
                        . '<img src="' . e($thumbSrc) . '" class="h-20 w-20 rounded border border-gray-700 object-cover bg-black/20 group-hover:opacity-90" alt="' . e($name) . ' thumbnail" />'
                        . '<span class="text-blue-400 underline break-all text-xs mt-1 group-hover:text-blue-300">' . e($name) . '</span>'
                        . '</a>';
                }

                // No usable href? Just return filename.
                return e($name);
            }

            // Normal mapping logic
            if (isset($map[$s])) return $map[$s];

            $low = strtolower($s);
            if ($low === 'yes' || $low === 'no') return ucfirst($low);

            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $s)) {
                try { return \Carbon\Carbon::parse($s)->format('d M Y'); } catch (\Throwable $e) {}
            }

            return ucwords(str_replace(['_', '-'], ' ', $s));
        }

        return json_encode($val);
    };
@endphp

<div class="space-y-4">
    @if (empty($qa))
        <div class="text-gray-400">No answers captured</div>
    @else
        @if ($isAssessment)
            <style>
              .pe-assess { border:1px solid #e5e7eb; border-radius:10px; background:#fff; }
              .pe-assess__sec { padding:14px 18px; border-bottom:1px solid #f1f5f9; }
              .pe-assess__sec:last-child { border-bottom:0; }
              .pe-assess__title { margin:0 0 10px; font:600 14px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji"; color:#0f172a; }
              .pe-assess dl { display:grid; grid-template-columns: 1fr 1.5fr; gap:6px 16px; font-size:14px; }
              .pe-assess dt { color:#6b7280; }
              .pe-assess dd { color:#111827; margin:0; }
            </style>
            <div class="pe-assess">
                @foreach ($sectionOrder as $__secTitle)
                    @php $items = $grouped[$__secTitle] ?? []; @endphp
                    @if (!empty($items))
                        <div class="pe-assess__sec">
                            @if ($__secTitle !== 'General')
                                <h3 class="pe-assess__title">{{ $__secTitle }}</h3>
                                <div style="height:1px;background:#f1f5f9;margin:8px 0 6px;"></div>
                            @endif
                            <dl>
                                @foreach ($items as $__row)
                                    <dt>{{ $__row['label'] }}</dt>
                                    <dd>{!! $pretty($__row['key'], $__row['answer']) !!}</dd>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <style>
              .pe-qa { border:1px solid #2d2d31; border-radius:10px; background:transparent; }
              .pe-qa__sep { height:1px; background:#2d2d31; }
              .pe-qa__title { padding:8px 14px; font-weight:600; color:#e5e7eb; }
              .pe-qa__row { display:grid; grid-template-columns: minmax(160px, 280px) 1fr; gap:8px 18px; padding:12px 16px; }
              .pe-qa dt { color:#9ca3af; margin:0; }
              .pe-qa dd { color:#e5e7eb; margin:0; word-break:break-word; }
            </style>

            <div class="pe-qa">
                @php $__prevSection = null; @endphp
                @foreach ($qa as $row)
                    @php
                        $key = $row['key'] ?? ($row['question'] ?? 'q_'.$loop->index);
                        if (isset($labels[$key])) {
                            $label = $labels[$key];
                        } elseif (preg_match('/^q_(\\d+)$/', (string) $key, $m) && isset($labels['q_'.$m[1]])) {
                            $label = $labels['q_'.$m[1]];
                        } else {
                            $savedQ = (string) ($row['question'] ?? '');
                            $label = preg_match('/^Question\s+\d+$/i', $savedQ)
                                ? ucwords(str_replace(['_', '-'], ' ', (string) $key))
                                : ($savedQ ?: ucwords(str_replace(['_', '-'], ' ', (string) $key)));
                        }

                        $answer = array_key_exists('raw', $row) && $row['raw'] !== null && $row['raw'] !== ''
                            ? $row['raw']
                            : ($row['answer'] ?? null);
                    @endphp

                    @php
                        $sectionName = null;
                        if (isset($sections[$key])) {
                            $sectionName = $sections[$key];
                        } elseif (preg_match('/^q_(\\d+)$/', (string) $key, $m) && isset($sections['q_'.$m[1]])) {
                            $sectionName = $sections['q_'.$m[1]];
                        }
                        if (!$sectionName && is_string($label) && $label !== '') {
                            $norm = strtolower(trim(preg_replace('/\\s+/', ' ', strip_tags($label))));
                            if ($norm !== '' && isset($sectionsByLabel[$norm])) {
                                $sectionName = $sectionsByLabel[$norm];
                            }
                        }
                    @endphp

                    @if ($sectionName && $sectionName !== $__prevSection)
                        <div class="pe-qa__sep"></div>
                        <div class="pe-qa__title">{{ $sectionName }}</div>
                        <div class="pe-qa__sep"></div>
                        @php $__prevSection = $sectionName; @endphp
                    @endif

                    <dl class="pe-qa__row">
                        <dt>{{ $label }}</dt>
                        <dd>{!! $pretty($key, $answer) !!}</dd>
                    </dl>
                @endforeach
            </div>
        @endif
    @endif
</div>