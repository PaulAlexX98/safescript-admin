@php
    $serviceFor = $serviceSlugForForm ?? ($session->service_slug ?? null);
    $treatFor   = $treatmentSlugForForm ?? ($session->treatment_slug ?? null);

    $q = \App\Models\ClinicForm::where('form_type', 'supply')->where('is_active', 1);
    if ($serviceFor) { $q->where('service_slug', $serviceFor); }
    if ($treatFor)   { $q->where('treatment_slug', $treatFor); }
    $form = $q->orderByDesc('version')->first();

    $schema = is_array($form?->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);

    $respQ = \App\Models\ConsultationFormResponse::where('consultation_session_id', $session->id)
        ->where('form_type', 'supply');
    if ($serviceFor) { $respQ->where('service_slug', $serviceFor); }
    if ($treatFor)   { $respQ->where('treatment_slug', $treatFor); }
    $resp = $respQ->first();

    $oldData = $resp?->data ?? [];
@endphp

@php
    // 1) Safely read meta from the **ApprovedOrder** for this session
    /** @var \App\Models\ApprovedOrder|null $approved */
    $approved = ($session->order instanceof \App\Models\ApprovedOrder)
        ? $session->order
        : (\App\Models\ApprovedOrder::query()->find($session->order_id));

    $metaRaw = $approved?->meta ?? [];
    $metaArr = is_array($metaRaw) ? $metaRaw : (json_decode($metaRaw ?: '[]', true) ?: []);

    // Prefer explicit selected product keys; support several historical shapes
    $selectedProduct = data_get($metaArr, 'selectedProduct')
        ?? data_get($metaArr, 'items.0')
        ?? data_get($metaArr, 'line_items.0')
        ?? data_get($metaArr, 'cart.items.0')
        ?? [];

    // Helper closures for deep value lookup and normalisation
    $findFirst = function ($arr, array $keys) {
        foreach ($keys as $k) {
            $v = data_get($arr, $k);
            if ($v !== null && $v !== '') return $v;
        }
        return null;
    };
    $deepSearchByName = function ($arr, array $nameNeedles) {
        // Search arrays like attributes/options where each entry has name and value
        if (is_array($arr)) {
            foreach ($arr as $item) {
                if (!is_array($item)) continue;
                $name = strtolower((string)($item['name'] ?? ''));
                foreach ($nameNeedles as $needle) {
                    if ($name && str_contains($name, strtolower($needle))) {
                        return $item['value'] ?? $item['label'] ?? null;
                    }
                }
            }
        }
        return null;
    };
    $extractStrength = function ($product, $meta) use ($findFirst, $deepSearchByName) {
        // Try explicit keys first
        $raw = $findFirst($product, ['strength', 'dose', 'dosage', 'attributes.strength', 'meta.strength'])
            ?? $findFirst($meta, ['strength', 'dose', 'dosage']);
        // Try common nested shapes and variation-like keys at meta root and product
        $raw = $raw
            ?? $deepSearchByName(data_get($product, 'attributes', []), ['strength', 'dose'])
            ?? $deepSearchByName(data_get($product, 'options', []), ['strength', 'dose'])
            ?? $deepSearchByName(data_get($product, 'variations', []), ['strength', 'dose'])
            // direct product keys commonly used for strength-as-variation
            ?? $findFirst($product, ['variation', 'variations', 'variant', 'option', 'optionLabel'])
            // some integrations store the chosen variation at the root of meta
            ?? $findFirst($meta, ['variation', 'variations', 'variant', 'option', 'optionLabel']);
        // Fall back to parsing from a variation/option text like "Mounjaro 5 mg"
        $variationText = $findFirst($product, ['variation', 'variations', 'variant', 'option', 'title']);
        $raw = $raw ?? $variationText;
        if (is_array($raw)) $raw = json_encode($raw);
        $raw = (string)($raw ?? '');
        if ($raw === '') return null;
        // Extract the first number with a unit if present
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mg|mcg|µg|micrograms?|units?|ml|mg\/ml)/i', $raw, $m)) {
            return trim($m[1] . ' ' . $m[2]);
        }
        // Otherwise just return the raw string
        return trim($raw);
    };
    $extractQuantity = function ($product, $meta) use ($findFirst, $deepSearchByName) {
        $raw = $findFirst($product, ['quantity', 'qty', 'count', 'units', 'meta.quantity'])
            ?? $findFirst($meta, ['quantity', 'qty', 'count', 'units']);
        $raw = $raw ?? $deepSearchByName(data_get($product, 'attributes', []), ['quantity', 'count', 'qty', 'units']);
        if ($raw === null || $raw === '') {
            // Sometimes encoded in titles like "x4" or "4 pens"
            $txt = (string)($findFirst($product, ['title', 'name', 'variation']) ?? '');
            if (preg_match('/(?:x\s*)?(\d{1,3})(?:\s*(?:pens|packs?|months?|injections?|pcs?))?/i', $txt, $m)) {
                $raw = $m[1];
            }
        }
        return $raw !== null ? (string)$raw : null;
    };

    $productName = (string)($findFirst($selectedProduct, ['name', 'title', 'product_name'])
        ?? $findFirst($metaArr, ['treatment', 'product', 'medicine'])
        ?? '');
    $strengthVal = $extractStrength($selectedProduct, $metaArr);
    $quantityVal = $extractQuantity($selectedProduct, $metaArr);

    $prefill = [
        'product'   => $productName ?: null,
        'strength'  => $strengthVal ?: null,
        'quantity'  => $quantityVal ?: null,
        'notes'     => $findFirst($metaArr, ['notes', 'clinical_notes', 'pharmacist_notes']) ?: null,
        'date'      => now()->format('Y-m-d'),
    ];

    // 2) Normalize $schema: fix select option labels, add decimal step for strength/dose
    $schemaNormalized = [];
    foreach (($schema ?? []) as $i => $field) {
        $f = $field;
        $type = $f['type'] ?? 'text_input';
        $data = (array)($f['data'] ?? []);
        $label = strtolower(trim((string)($data['label'] ?? ($f['label'] ?? ''))));

        if ($type === 'select') {
            $opts = (array)($data['options'] ?? []);
            $fixed = [];
            foreach ($opts as $ov => $ol) {
                if (is_array($ol)) {
                    $val = $ol['value'] ?? $ov;
                    $lbl = $ol['label'] ?? $val;
                    $fixed[] = ['value' => $val, 'label' => $lbl];
                } else {
                    $fixed[] = ['value' => (string)$ol, 'label' => (string)$ol];
                }
            }
            $data['options'] = $fixed;
        }

        // Give the strength field a decimal step
        if ($type === 'number' && (str_contains($label,'strength') || str_contains($label,'dose'))) {
            $data['step'] = $data['step'] ?? '0.1';
        }

        $f['data'] = $data;
        $schemaNormalized[$i] = $f;
    }
    $schema = $schemaNormalized;

    // 3) Prefill $oldData based on field index and label
    $oldDataPrefill = is_array($oldData) ? $oldData : [];
    foreach (($schema ?? []) as $i => $field) {
        $type = $field['type'] ?? 'text_input';
        $data = (array)($field['data'] ?? []);
        $label = strtolower(trim((string)($data['label'] ?? ($field['label'] ?? ''))));
        $name = $field['name'] ?? ($type === 'text_block' ? ('block_'.$i) : ('field_'.$i));

        if (!array_key_exists($name, $oldDataPrefill) || $oldDataPrefill[$name] === '' || $oldDataPrefill[$name] === null) {
            // Date
            if ($type === 'date' && !empty($prefill['date'])) {
                $oldDataPrefill[$name] = $prefill['date'];
                continue;
            }

            // Product selects
            if ($type === 'select' && !empty($prefill['product']) && !str_contains($label, 'quantity')) {
                $opts = (array)($data['options'] ?? []);
                $want = strtolower((string)$prefill['product']);
                $chosen = null;
                foreach ($opts as $opt) {
                    $val = (string)($opt['value'] ?? $opt['label'] ?? '');
                    $lab = (string)($opt['label'] ?? $opt['value'] ?? '');
                    if (strtolower($val) === $want || strtolower($lab) === $want) { $chosen = $val; break; }
                    if ($chosen === null && ($want !== '') && (str_contains(strtolower($lab), $want) || str_contains(strtolower($val), $want))) { $chosen = $val; }
                }
                $oldDataPrefill[$name] = $chosen ?? $prefill['product'];
                continue;
            }

            // Strength text or number any generic input type
            $isTextish = in_array($type, ['number','text_input','text','input','short_text','long_text'], true) || (!in_array($type, ['select','date','text_block'], true));
            if ($isTextish && (str_contains($label,'strength') || str_contains($label,'dose') || str_contains($label,'variation')) && !empty($prefill['strength'])) {
                $oldDataPrefill[$name] = (string)$prefill['strength'];
                continue;
            }

            // Strength select (handles labels like Strength Dose Variation)
            if ($type === 'select' && (str_contains($label,'strength') || str_contains($label,'dose') || str_contains($label,'variation')) && !empty($prefill['strength'])) {
                $opts = (array)($data['options'] ?? []);
                $want = (string)$prefill['strength'];
                $normalize = function ($s) {
                    $s = strtolower((string)$s);
                    // remove spaces, punctuation, currency symbols etc for robust matching
                    return preg_replace('/[^a-z0-9]/', '', $s);
                };
                $wantNorm = $normalize($want);
                $chosen = null;
                foreach ($opts as $opt) {
                    $val = (string)($opt['value'] ?? $opt['label'] ?? '');
                    $lab = (string)($opt['label'] ?? $opt['value'] ?? '');
                    if ($normalize($val) === $wantNorm || $normalize($lab) === $wantNorm) { $chosen = $val; break; }
                    // numeric fallback match  e.g. 5 vs "5mg" or "5 mg"
                    if (preg_match('/\d+(?:\.\d+)?/', $want, $mWant) && preg_match('/\d+(?:\.\d+)?/', $lab . ' ' . $val, $mOpt) && $mWant[0] === $mOpt[0]) {
                        $chosen = $val; // keep looking in case of exact match, but set a candidate
                    }
                }
                $oldDataPrefill[$name] = $chosen ?? $prefill['strength'];
                continue;
            }

            // Quantity number or text
            if (($type === 'number' || $type === 'text_input') && str_contains($label,'quantity') && !empty($prefill['quantity'])) {
                $oldDataPrefill[$name] = (string)$prefill['quantity'];
                continue;
            }

            // Quantity select
            if ($type === 'select' && str_contains($label,'quantity') && !empty($prefill['quantity'])) {
                $opts = (array)($data['options'] ?? []);
                $wantQ = strtolower((string)$prefill['quantity']);
                $chosenQ = null;
                foreach ($opts as $opt) {
                    $val = (string)($opt['value'] ?? $opt['label'] ?? '');
                    $lab = (string)($opt['label'] ?? $opt['value'] ?? '');
                    // Exact or numeric match
                    if (strtolower($val) === $wantQ || strtolower($lab) === $wantQ) { $chosenQ = $val; break; }
                    if (preg_match('/\d+/', $wantQ, $mWant) && preg_match('/\d+/', $lab . ' ' . $val, $mOpt) && $mWant[0] === $mOpt[0]) { $chosenQ = $val; }
                }
                $oldDataPrefill[$name] = $chosenQ ?? $prefill['quantity'];
                continue;
            }

            // Notes
            if (str_contains($label,'note') && !empty($prefill['notes'])) {
                $oldDataPrefill[$name] = (string)$prefill['notes'];
                continue;
            }
        }
    }
    $oldData = $oldDataPrefill;
@endphp

@once
<style>
  /* Scoped to this page only */
  .ros-card { border-radius: 12px; border: 1px solid rgba(255,255,255,.08); padding: 20px; }
  .ros-card p { margin: .4rem 0 .9rem; line-height: 1.6; }
  .ros-card h1, .ros-card h2, .ros-card h3 { margin: .8rem 0 .5rem; }
  /* Make every label start a new block and create gentle separators between fields */
  .ros-card label { display:block; margin: 18px 0 6px; font-weight: 600; color: #e5e7eb; }
  .ros-card label + input,
  .ros-card label + select,
  .ros-card label + textarea { margin-top: 6px; }
  .ros-card label:not(:first-of-type) { border-top: 1px solid rgba(255,255,255,.08); padding-top: 14px; }
  /* Inputs: half-width on desktop, full on small screens */
  .ros-card input[type="text"],
  .ros-card input[type="number"],
  .ros-card input[type="date"],
  .ros-card select { width: 50%; min-width: 280px; max-width: 600px; padding: .65rem .8rem; border: 2px solid #6b7280; border-radius: 8px; color: #e5e7eb; outline: none; transition: border-color .2s ease, box-shadow .2s ease; }
  .ros-card input[type="text"]:hover,
  .ros-card input[type="number"]:hover,
  .ros-card input[type="date"]:hover,
  .ros-card select:hover { border-color: #f59e0b; }
  .ros-card input[type="text"]:focus,
  .ros-card input[type="number"]:focus,
  .ros-card input[type="date"]:focus,
  .ros-card select:focus { border-color: #fbbf24; box-shadow: 0 0 0 3px rgba(251,191,36,.25); }
  /* Notes area full-width */
  .ros-card textarea { width: 100%; min-height: 120px; border: 1px solid #374151; border-radius: 10px; color: #e5e7eb; padding: 12px 14px; }
  @media (max-width: 768px){
    .ros-card input[type="text"], .ros-card input[type="number"], .ros-card input[type="date"], .ros-card select { width: 100%; }
  }
  .ros-card form button[type="submit"],
  .ros-card form [type="submit"] {
    display: none !important;
  }
</style>
@if(request()->boolean('debug'))
  <div style="margin:8px 0;padding:8px 12px;border:1px dashed #6b7280;border-radius:8px;font-size:12px;color:#e5e7eb">
    <strong>Debug</strong>
    <div>prefill strength = {{ e((string)($prefill['strength'] ?? '')) }}</div>
    <div>schema count = {{ count($schema ?? []) }}</div>
    <details style="margin-top:6px">
      <summary>fields</summary>
      <ul style="margin:6px 0 0 14px; list-style: disc">
        @foreach(($schema ?? []) as $i => $f)
          @php $d = (array)($f['data'] ?? []); $lab = strtolower(trim((string)($d['label'] ?? ($f['label'] ?? '')))); $t = $f['type'] ?? 'text_input'; @endphp
          <li>{{ e($f['name'] ?? ('field_'.$i)) }}  type {{ e($t) }}  label {{ e($lab) }}</li>
        @endforeach
      </ul>
    </details>
  </div>
@endif
<script>
  document.addEventListener('DOMContentLoaded', function(){
    const root = document.querySelector('.ros-card');
    if (!root) return;
    root.querySelectorAll('button, [role="button"]').forEach(function(btn){
      const t = (btn.textContent || '').toLowerCase().trim();
      if (t === 'save' || t === 'save and next' || (t.includes('save') && t.includes('next'))) {
        const wrap = btn.closest('div');
        if (wrap) { wrap.remove(); } else { btn.remove(); }
      }
    });
    // Allow decimals for strength/dose number inputs
    root.querySelectorAll('label').forEach(function(lab){
      const t = (lab.textContent||'').toLowerCase();
      if (t.includes('strength') || t.includes('dose')){
        const inp = lab.nextElementSibling;
        if (inp && inp.tagName === 'INPUT') { inp.setAttribute('step','0.1'); inp.setAttribute('inputmode','decimal'); }
      }
    });
  });
</script>
@endonce

<div class="ros-card">
  @include('consultations._form', [
      'session' => $session,
      'slug' => 'record-of-supply',
      'form' => $form,
      'schema' => $schema,
      'oldData' => $oldData
  ])
</div>