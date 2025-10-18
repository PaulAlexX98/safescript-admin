<style>
  /* Scoped styles for the assessment card to avoid relying on Tailwind presence */
  .pe-assess { border:1px solid #e5e7eb; border-radius:10px; background:#fff; }
  .pe-assess__sec { padding:14px 18px; border-bottom:1px solid #f1f5f9; }
  .pe-assess__sec:last-child { border-bottom:0; }
  .pe-assess__title { margin:0 0 10px; font:600 14px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji"; color:#0f172a; }
  .pe-assess dl { display:grid; grid-template-columns: 1fr 1.5fr; gap:6px 16px; font-size:14px; }
  .pe-assess dt { color:#6b7280; }
  .pe-assess dd { color:#111827; margin:0; }
  .pe-assess .full { grid-column: 1 / -1; }
  .pe-assess .muted { color:#9ca3af; }
  .pe-assess .badge { display:inline-flex; align-items:center; border-radius:999px; padding:2px 8px; font-size:12px; font-weight:600; }
  .pe-assess .badge--yes { background:#d1fae5; color:#065f46; }
  .pe-assess .badge--no { background:#f3f4f6; color:#374151; }
  .pe-assess .chip { display:inline-flex; align-items:center; border-radius:999px; padding:2px 8px; font-size:12px; background:#f4f4f5; color:#27272a; margin:0 6px 6px 0; }
</style>
@php
    /** @var array|null $state */
    $a = $getState() ?? [];

    // --- Field groups (keys aligned to frontend components) ---
    $groups = [
        'About you' => [
            'age_18_to_85' => 'Age 18–85',
            'ethnicity' => 'Ethnicity',
            'pregnant_or_breastfeeding_or_planning' => 'Pregnant / Breastfeeding / Planning',
            'preg_text' => 'Pregnancy / breastfeeding details',
            'height_input' => 'Height',
            'weight_input' => 'Weight',
            'bmi' => 'BMI',
            'target_weight' => 'Target Weight',
            'scale_image_name' => 'Scale image name',
            'scale_image_size' => 'Scale image size',
            'scale_image_attached' => 'Scale image attached',
            'body_image_name' => 'Body image name',
            'body_image_size' => 'Body image size',
            'body_image_attached' => 'Body image attached',
        ],

        'Conditions & medicines' => [
            // Q10–Q20 from ConditionsMedicines.tsx
            'weight_related_conditions' => 'Weight related conditions',
            'smoke' => 'Smoke',
            'want_stop_smoking_info' => 'Wants stop‑smoking info',
            'drink_alcohol' => 'Drink alcohol',
            'want_alcohol_info' => 'Wants alcohol info',
            'prior_weightloss_or_led' => 'Prior WL / LED',
            'prior_weightloss_details' => 'Prior WL / LED details',
            'require_evidence_yes' => 'Evidence required',
            'evidence_image_name' => 'Evidence file name',
            'evidence_image_size' => 'Evidence file size',
            'evidence_image_attached' => 'Evidence image attached',
            'eating_disorder' => 'Eating disorder',
            'eating_disorder_text' => 'Eating disorder details',
            'has_conditions_yes' => 'Has conditions (screen)',
            'has_conditions' => 'Conditions',
            'conditions_text' => 'Conditions notes',
            'has_medicines_yes' => 'Takes medicines (screen)',
            'has_medicines_list' => 'Medicines list',
            'meds_text' => 'Medicines notes',
            'oral_contraceptives' => 'Oral contraceptives',
            'ocp_details' => 'OCP details',
            'exercise_4_5_per_week' => 'Exercise 4–5× per week',
            'exercise_text' => 'Exercise details',
            'daily_calories' => 'Daily calories',
        ],

        'Past medical history' => [
            // Q21–Q25 from PastHistory.tsx
            'kidney_or_liver_impairment' => 'Kidney or liver impairment',
            'kidney_or_liver_impairment_text' => 'Kidney/liver impairment details',
            'other_medical_conditions_yes' => 'Other medical conditions (screen)',
            'other_medical_conditions_text' => 'Other medical notes',
            'current_or_recent_meds_yes' => 'Current or recent meds (screen)',
            'current_or_recent_meds_text' => 'Current/recent meds notes',
            'allergies_yes' => 'Allergies (screen)',
            'allergies_text' => 'Allergies notes',
            'gp_selected' => 'GP selected',
            'gp_name' => 'GP name',
            'gp_address' => 'GP address',
            'gp_ods_code' => 'GP ODS code',
            'gp_email' => 'GP email',
            'gp_email_submitted' => 'GP email submitted',
        ],

        'Declarations & consent' => [
            'ack_needles_swabs_bin' => 'Needles / swabs / sharps bin',
            'ack_first_attempt_delivery' => 'Accept first delivery attempt',
            'consent_scr_access' => 'SCR access consent',
            'ack_treatment_rules' => 'Agrees to treatment rules',
            'final_declaration' => 'Final declaration',
        ],
    ];

    // --- helpers ---
    $yesNo = fn ($v) => ($v === true || $v === 'yes' || $v === 'Yes') ? 'Yes'
                          : (($v === false || $v === 'no' || $v === 'No') ? 'No' : $v);

    $bytesToHuman = function ($bytes) {
        if (!is_numeric($bytes)) return $bytes;
        $bytes = (float) $bytes;
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024; $i++;
        }
        return number_format($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    };

    use Illuminate\Support\Facades\Storage;

    // Image labels and resolver
    $imageLabels = [
        'scale_image'    => 'Scale photo',
        'body_image'     => 'Body photo',
        'evidence_image' => 'Evidence file',
    ];

    $resolveImageUrl = function (string $prefix) use ($a) {
        $url = $a[$prefix . '_image_url'] ?? null;
        if (!$url && !empty($a[$prefix . '_image_path'])) {
            try {
                $url = Storage::url($a[$prefix . '_image_path']);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return $url;
    };

    $mapValueByKey = function (string $key, $value) use ($bytesToHuman) {
        if ($key === 'bmi' && is_numeric($value)) {
            return number_format((float) $value, 1) . ' kg/m²';
        }
        if (str_ends_with($key, '_size')) {
            return $bytesToHuman($value);
        }
        if ($key === 'daily_calories') {
            $map = [
                // support both old and new FE ids
                'under1200'   => 'Under 1,200 a day',
                '1200to1500'  => '1,200–1,500 a day',
                'under1500'   => 'Less than 1,500 a day',
                '1500to2500'  => '1,500–2,500 a day',
                'over2500'    => 'More than 2,500 a day',
            ];
            return $map[$value] ?? $value;
        }
        return $value;
    };

    $renderValue = function ($key, $v) use ($yesNo, $mapValueByKey) {
        $v = $mapValueByKey($key, $v);

        // booleans -> badges
        if (is_bool($v) || in_array($v, ['yes','no','Yes','No'], true)) {
            $text = $yesNo($v);
            $isYes = $text === 'Yes';
            return '<span class="badge '.($isYes ? 'badge--yes' : 'badge--no').'">'.e($text).'</span>';
        }

        // arrays -> chips
        if (is_array($v)) {
            if (empty($v)) return '<span class="muted">—</span>';
            return collect($v)->map(fn($x) => '<span class="chip">'.e($x).'</span>')->implode(' ');
        }

        // null/empty -> muted dash
        if ($v === null || $v === '') {
            return '<span class="muted">—</span>';
        }

        return e($v);
    };

    $renderImageRow = function (string $prefix) use ($a, $imageLabels, $bytesToHuman, $resolveImageUrl) {
        $attached = $a[$prefix . '_image_attached'] ?? null;
        $name     = $a[$prefix . '_image_name'] ?? null;
        $size     = $a[$prefix . '_image_size'] ?? null;
        $url      = $resolveImageUrl($prefix);

        // If nothing meaningful, return empty string to skip rendering
        if (!$attached && !$name && !$url) {
            return '';
        }

        $label = $imageLabels[$prefix] ?? ucfirst(str_replace('_', ' ', $prefix));

        $meta  = trim(implode(' • ', array_filter([
            $name ?: null,
            $size ? $bytesToHuman($size) : null,
            $attached === true ? 'attached' : null,
        ])));

        $thumb = $url
            ? '<a href="'.e($url).'" target="_blank" rel="noreferrer"><img src="'.e($url).'" alt="'.e($label).'" style="max-width:220px; max-height:220px; border:1px solid #e5e7eb; border-radius:8px"></a>'
            : '<span class="muted">Preview unavailable</span>';

        return '<dt>'.e($label).'</dt><dd class="full">'
               . $thumb
               . ($meta ? '<div style="margin-top:6px; font-size:12px; color:#6b7280">'.e($meta).'</div>' : '')
               . '</dd>';
    };
@endphp

@if (empty($a))
    <div style="font-size:14px;color:#64748b;">No answers submitted</div>
@else
<div class="pe-assess">
    @foreach ($groups as $title => $fields)
        @php
            // only render the section if at least one field exists in answers
            $hasAny = collect($fields)->keys()->some(fn($k) => array_key_exists($k, $a));
        @endphp
        @if ($hasAny)
            <div class="pe-assess__sec">
                <h3 class="pe-assess__title">{{ $title }}</h3>
                <dl>
                    @php $renderedImages = []; @endphp
                    @foreach ($fields as $key => $label)
                        @php
                            // Handle grouped image fields like scale_image_*, body_image_*, evidence_image_*
                            if (preg_match('/^(.*)_image_(name|size|attached|url|path)$/', $key, $m)) {
                                $prefix = $m[1];
                                if (!in_array($prefix, $renderedImages, true)) {
                                    echo $renderImageRow($prefix);
                                    $renderedImages[] = $prefix;
                                }
                                // Skip default rendering for image subfields
                                continue;
                            }
                        @endphp
                        @if (array_key_exists($key, $a))
                            @php $val = $renderValue($key, $a[$key]); @endphp
                            @if (!str_contains($val, 'muted'))
                                <dt>{{ $label }}</dt>
                                <dd>{!! $val !!}</dd>
                            @endif
                        @endif
                    @endforeach
                </dl>
            </div>
        @endif
    @endforeach
</div>
@endif