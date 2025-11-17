@php
    use Illuminate\Support\Arr;
    use Illuminate\Support\Facades\Http;

    // Helper normaliser accept array stdClass or JSON string and return array
    $arr = function ($v) {
        if (is_array($v)) return $v;
        if (is_object($v)) return json_decode(json_encode($v), true) ?: [];
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : [];
        }
        return [];
    };

    // Resolve $record from Filament ViewEntry when provided as a callable
    if (!isset($record) && isset($getRecord) && is_callable($getRecord)) {
        $record = $getRecord();
    }

    // 1) Load meta and answers from the order
    $recMeta = isset($record) ? ($record->meta ?? []) : [];
    // Normalise stdClass | array | JSON string into a plain array so data_get works
    $meta    = $arr($recMeta);

    // Hard lock to meta.assessment.answers if present
    $qa = [];
    $qaSource = '';
    $qaKeyUsed = 'assessment';
    $aa = data_get($meta, 'assessment.answers');

    if ($aa instanceof \stdClass) { $aa = json_decode(json_encode($aa), true) ?: []; }
    if (is_string($aa)) {
        $tmp = json_decode($aa, true);
        if (json_last_error() === JSON_ERROR_NONE) $aa = $tmp;
    }

    if (is_array($aa) && !empty($aa)) {
        foreach ($aa as $k => $v) {
            $qa[] = ['key' => (string) $k, 'question' => \Illuminate\Support\Str::headline((string) $k), 'answer' => $v];
        }
        $qaSource = 'meta.assessment.answers';
    }

    // Keep these for the probe and later pretty printing only
    $stateArr = $arr($state ?? []);
    $formsQA  = $arr(data_get($meta, 'formsQA') ?: data_get($meta, 'consultation.formsQA', []));

    // formsQA-only finder  no cross-record lookups
    $findQa = function ($node) use (&$findQa, $arr) {
        if ($node instanceof \stdClass) $node = (array) $node;
        if (!is_array($node)) return [];

        // if root is already a rows list like [['key'=>..,'question'=>..,'answer'=>..], ...]
        if (isset($node[0]) && is_array($node[0]) && (isset($node[0]['question']) || isset($node[0]['key']))) {
            return array_values($node);
        }

        // direct qa array
        if (isset($node['qa']) && is_array($node['qa']) && !empty($node['qa'])) {
            return array_values($node['qa']);
        }

        // assessment.answers map under formsQA
        if (isset($node['assessment']['answers']) && is_array($node['assessment']['answers'])) {
            $out = [];
            foreach ($node['assessment']['answers'] as $k => $v) {
                $out[] = ['key' => (string) $k, 'question' => \Illuminate\Support\Str::headline((string) $k), 'answer' => $v];
            }
            return $out;
        }

        // NEW handle direct 'answers' map under this node
        if (isset($node['answers']) && is_array($node['answers']) && !empty($node['answers'])) {
            $out = [];
            foreach ($node['answers'] as $k => $v) {
                $out[] = ['key' => (string) $k, 'question' => \Illuminate\Support\Str::headline((string) $k), 'answer' => $v];
            }
            return $out;
        }

        // NEW handle flat associative maps like ["changes"=>"yes", ...] (no qa key)
        if (\Illuminate\Support\Arr::isAssoc($node)) {
            $allScalar = true;
            foreach ($node as $k => $v) {
                if (!(is_scalar($v) || $v === null)) { $allScalar = false; break; }
            }
            if ($allScalar && !isset($node['schema']) && !isset($node['components']) && !isset($node['qa'])) {
                $out = [];
                foreach ($node as $k => $v) {
                    // ignore obvious non-answer keys
                    if (in_array((string) $k, ['schema','components','type','label','title'], true)) continue;
                    $out[] = [
                        'key'      => (string) $k,
                        'question' => \Illuminate\Support\Str::headline((string) $k),
                        'answer'   => $v,
                    ];
                }
                if (!empty($out)) return $out;
            }
        }

        // shallow first level scan for qa or rows arrays
        foreach ($node as $v) {
            if ($v instanceof \stdClass) $v = (array) $v;
            if (!is_array($v)) continue;

            if (isset($v['qa']) && is_array($v['qa']) && !empty($v['qa'])) {
                return array_values($v['qa']);
            }

            if (isset($v[0]) && is_array($v[0]) && (isset($v[0]['question']) || isset($v[0]['key']))) {
                return array_values($v);
            }
        }

        // recursive scan restricted to formsQA only
        foreach ($node as $v) {
            if ($v instanceof \stdClass || is_array($v)) {
                $found = $findQa($v);
                if (!empty($found)) return $found;
            }
        }

        return [];
    };

    // 1) record accessor answers first
    if (empty($qa) && isset($record)) {
        $aaRec = $record->answers ?? null;
        if (is_string($aaRec)) {
            $tmp = json_decode($aaRec, true);
            if (json_last_error() === JSON_ERROR_NONE) $aaRec = $tmp;
        }
        if (is_array($aaRec) && !empty($aaRec)) {
            foreach ($aaRec as $k => $v) {
                $lbl = \Illuminate\Support\Str::headline((string) $k);
                $qa[] = ['key' => (string) $k, 'question' => $lbl, 'answer' => $v];
            }
            $qaSource = 'record.answers';
        }
    }

    // 2) meta assessment.answers or answers
    if (empty($qa)) {
        $aa = data_get($meta, 'assessment.answers')
            ?? data_get($meta, 'assessment_answers')
            ?? data_get($meta, 'answers');

        // Coerce stdClass or JSON string into array
        if ($aa instanceof \stdClass) {
            $aa = json_decode(json_encode($aa), true) ?: [];
        } elseif (is_string($aa)) {
            $tmp = json_decode($aa, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $aa = $tmp;
            }
        }

        if (is_array($aa) && !empty($aa)) {
            foreach ($aa as $k => $v) {
                $lbl = \Illuminate\Support\Str::headline((string) $k);
                $qa[] = ['key' => (string) $k, 'question' => $lbl, 'answer' => $v];
            }
            $qaSource = 'meta.assessment.answers|answers';
        }
    }

    // 3) only if still empty, fall back to formsQA present on this record
    if (empty($qa)) {
        $stateLooksLikeForms = is_array($stateArr)
            && (data_get($stateArr, 'assessment.qa') || data_get($stateArr, 'risk_assessment.qa') || data_get($stateArr, 'raf.qa'));

        $formsQA = $stateLooksLikeForms
            ? $stateArr
            : $arr(data_get($meta, 'formsQA') ?: data_get($meta, 'consultation.formsQA', []));

        // Try common direct paths inside formsQA before the generic scanner
        if (empty($qa)) {
            $direct = [
                'assessment.qa',
                'assessment.answers',
                'assessment.data.qa',
                'assessment.data.answers',
                'assessment.values',
                'risk_assessment.qa',
                'risk_assessment.answers',
                'risk_assessment.data.qa',
                'risk_assessment.data.answers',
                'raf.qa',
                'raf.answers',
                'reorder.qa',
                'reorder.answers',
                'reorder.data.qa',
                'reorder.data.answers',
            ];
            foreach ($direct as $path) {
                $v = data_get($formsQA, $path);
                if (empty($v)) continue;
                // normalise strings and objects
                if (is_string($v)) {
                    $tmp = json_decode($v, true);
                    if (json_last_error() === JSON_ERROR_NONE) $v = $tmp;
                } elseif ($v instanceof \stdClass) {
                    $v = json_decode(json_encode($v), true) ?: [];
                }
                if (!is_array($v) || empty($v)) continue;
                // If it's a flat associative answers map, convert to rows
                if (\Illuminate\Support\Arr::isAssoc($v) && !isset($v[0])) {
                    $rows = [];
                    foreach ($v as $k => $val) {
                        if (in_array((string)$k, ['schema','components','type','label','title'], true)) continue;
                        $rows[] = [
                            'key'      => (string) $k,
                            'question' => \Illuminate\Support\Str::headline((string) $k),
                            'answer'   => $val,
                        ];
                    }
                    if (!empty($rows)) {
                        $qa = $rows;
                        $qaSource = 'formsQA.' . $path;
                        $qaKeyUsed = str_contains($path, 'raf') ? 'raf' : 'assessment';
                        break;
                    }
                }
                // If it's already rows like [{question:, answer:}...]
                if (isset($v[0]) && is_array($v[0]) && (isset($v[0]['question']) || isset($v[0]['key']) || isset($v[0]['answer']))) {
                    $qa = array_values($v);
                    $qaSource = 'formsQA.' . $path;
                    $qaKeyUsed = str_contains($path, 'reorder')
                        ? 'reorder'
                        : (str_contains($path, 'raf') ? 'raf' : 'assessment');
                    break;
                }
            }
        }

        $qa = $findQa($formsQA);
        if (!empty($qa) && $qaSource === '') { $qaSource = 'formsQA.scan'; }
        if (!empty($qa) && empty($qaKeyUsed)) {
            if (isset($formsQA['reorder'])) { $qaKeyUsed = 'reorder'; }
        }
    } else {
        // ensure formsQA exists for probe
        $formsQA = $arr(data_get($meta, 'formsQA') ?: []);
    }

    // Fallback C  deep-scan meta for any plausible answers structure
    if (empty($qa) && is_array($meta) && !empty($meta)) {
        $normaliseMapToRows = function ($map) {
            if (!is_array($map) || empty($map)) return [];
            $out = [];
            foreach ($map as $k => $v) {
                // only accept simple scalar-ish keys
                if (!is_string($k) && !is_int($k)) continue;
                $label = \Illuminate\Support\Str::headline((string) $k);
                $out[] = [
                    'key' => (string) $k,
                    'question' => $label,
                    'answer' => $v,
                ];
            }
            return $out;
        };

        $stack = [$meta];
        $foundRows = [];
        while ($stack) {
            $node = array_pop($stack);
            if ($node instanceof \stdClass) $node = (array) $node;
            if (!is_array($node)) continue;

            // Case 1  explicit QA rows under a `qa` key
            if (isset($node['qa']) && is_array($node['qa']) && !empty($node['qa']) && isset($node['qa'][0])) {
                $rows = [];
                foreach ($node['qa'] as $r) {
                    if ($r instanceof \stdClass) $r = (array) $r;
                    if (!is_array($r)) continue;
                    $rows[] = [
                        'key' => (string) ($r['key'] ?? ($r['question'] ?? '')),
                        'question' => (string) ($r['question'] ?? (isset($r['key']) ? \Illuminate\Support\Str::headline((string) $r['key']) : 'Question')),
                        'answer' => $r['answer'] ?? ($r['raw'] ?? null),
                    ];
                }
                if (!empty($rows)) { $foundRows = $rows; break; }
            }

            // Case 2  associative `answers` map of key => value
            if (isset($node['answers']) && is_array($node['answers']) && !empty($node['answers'])) {
                $rows = $normaliseMapToRows($node['answers']);
                if (!empty($rows)) { $foundRows = $rows; break; }
            }

            // Case 3  look for q_N style map
            $qKeys = array_filter(array_keys($node), fn($k) => is_string($k) && preg_match('/^q_\d+$/', $k));
            if (!empty($qKeys)) {
                $rows = $normaliseMapToRows($node);
                if (!empty($rows)) { $foundRows = $rows; break; }
            }

            // Recurse into children
            foreach ($node as $v) {
                if ($v instanceof \stdClass || is_array($v)) $stack[] = $v;
            }
        }

        if (!empty($foundRows)) {
            $qa = $foundRows;
            $qaSource = 'meta.deep';
            $qaKeyUsed = 'assessment';
        }
    }


    // Ensure assessmentAnswers exists and is an array for fallback rendering later
    $assessmentAnswers = [];
    try {
        $aa = data_get($meta, 'assessment.answers')
            ?? data_get($meta, 'assessment_answers')
            ?? data_get($meta, 'answers');

        if (is_string($aa)) {
            $decodedAa = json_decode($aa, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAa)) {
                $assessmentAnswers = $decodedAa;
            }
        } elseif (is_array($aa)) {
            $assessmentAnswers = $aa;
        }
    } catch (\Throwable $e) {
        $assessmentAnswers = [];
    }

    // 2) Fetch the exact RAF schema from API using service slug
    $serviceSlug = data_get($meta, 'service_slug') ?: data_get($meta, 'consultation.service_slug');
    $apiBase = config('services.pharmacy_api.base')
        ?? env('API_BASE')
        ?? env('NEXT_PUBLIC_API_BASE')
        ?? config('app.url');

    $rafSchema = [];
    $assessmentSchema = [];
    $reorderSchema = [];
    $activeSchema = [];
    if ($serviceSlug) {
        try {
            $resp = Http::timeout(3)
                ->acceptJson()
                ->get(rtrim($apiBase, '/')."/api/services/{$serviceSlug}/forms");

            if ($resp->ok()) {
                $json = $resp->json();

                $rafSchema = $arr(data_get($json, 'raf_form.schema') ?? data_get($json, 'raf.schema') ?? []);
                $reorderSchema = $arr(
                    data_get($json, 'reorder_form.schema')
                    ?? data_get($json, 'reorder.schema')
                    ?? []
                );

                $assessmentSchema = $arr(
                    data_get($json, 'assessment_form.schema')
                    ?? data_get($json, 'assessment.schema')
                    ?? data_get($json, 'risk_assessment_form.schema')
                    ?? data_get($json, 'risk_assessment.schema')
                    ?? []
                );
            }
        } catch (\Throwable $e) {
            $rafSchema = [];
            $assessmentSchema = [];
        }
    }

    // Fallbacks from stored meta if API returned none
    if (empty($assessmentSchema)) {
        $tmp = $arr(data_get($formsQA, 'assessment.schema') ?? data_get($formsQA, 'risk_assessment.schema') ?? null);
        if (!empty($tmp)) {
            $assessmentSchema = $tmp;
        }
    }

    if (empty($rafSchema)) {
        $tmp = $arr(data_get($formsQA, 'raf.schema') ?? null);
        if (!empty($tmp)) {
            $rafSchema = $tmp;
        }
    }
    if (empty($reorderSchema)) {
        $tmp = $arr(data_get($formsQA, 'rerorder.schema') ?? null);
        if (!empty($tmp)) {
            $reorderSchema = $tmp;
        }
    }

    if (in_array($qaKeyUsed, ['assessment','risk_assessment'], true)) {
        $activeSchema = $assessmentSchema ?: $rafSchema;
    } elseif ($qaKeyUsed === 'reorder') {
        $activeSchema = $reorderSchema ?: $rafSchema;
    } else {
        $activeSchema = $rafSchema;
    }

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

    // Seed labels from QA keys if schema did not provide them
    if (empty($labels) && !empty($qa)) {
        foreach ($qa as $__row) {
            $k = (string) (\Illuminate\Support\Arr::get($__row, 'key') ?? '');
            if ($k !== '' && !isset($labels[$k])) {
                $labels[$k] = \Illuminate\Support\Str::headline($k);
            }
        }
    }

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
            // Resolve label with placeholder guard: ignore generic "Question N" text
            $placeholder = function ($s) {
                return is_string($s) && preg_match('/^\s*Question\s+\d+\s*$/i', $s);
            };

            if (isset($row['label']) && is_string($row['label']) && trim($row['label']) !== '' && ! $placeholder($row['label'])) {
                $label = (string) $row['label'];
            } elseif (isset($labels[$key])) {
                $label = $labels[$key];
            } elseif (preg_match('/^q_(\d+)$/', (string) $key, $m) && isset($labels['q_'.$m[1]])) {
                $label = $labels['q_'.$m[1]];
            } elseif (isset($row['question']) && is_string($row['question']) && trim($row['question']) !== '' && ! $placeholder($row['question'])) {
                $label = (string) $row['question'];
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
@php
    $formsQAKeys = is_array($formsQA ?? null) ? implode(',', array_slice(array_keys($formsQA), 0, 8)) : '';
    $len = function ($v) {
        if (is_string($v)) { $d = json_decode($v, true); $v = (json_last_error()===JSON_ERROR_NONE)?$d:$v; }
        if ($v instanceof \stdClass) $v = json_decode(json_encode($v), true) ?: [];
        return is_array($v) ? count($v) : 0;
    };
    $assQa  = $len(data_get($formsQA ?? [], 'assessment.qa'));
    $assAns = $len(data_get($formsQA ?? [], 'assessment.answers'));
    $assDq  = $len(data_get($formsQA ?? [], 'assessment.data.qa'));
    $assDa  = $len(data_get($formsQA ?? [], 'assessment.data.answers'));
    $riskQa = $len(data_get($formsQA ?? [], 'risk_assessment.qa'));
    $riskAn = $len(data_get($formsQA ?? [], 'risk_assessment.answers'));
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
                        $placeholder = function ($s) {
                            return is_string($s) && preg_match('/^\s*Question\s+\d+\s*$/i', $s);
                        };
                        if (isset($row['label']) && is_string($row['label']) && trim($row['label']) !== '' && ! $placeholder($row['label'])) {
                            $label = (string) $row['label'];
                        } elseif (isset($labels[$key])) {
                            $label = $labels[$key];
                        } elseif (preg_match('/^q_(\\d+)$/', (string) $key, $m) && isset($labels['q_'.$m[1]])) {
                            $label = $labels['q_'.$m[1]];
                        } elseif (isset($row['question']) && is_string($row['question']) && trim($row['question']) !== '' && ! $placeholder($row['question'])) {
                            $label = (string) $row['question'];
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