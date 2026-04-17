{{-- resources/views/consultations/complete.blade.php --}}

@once
    <style>
      .cf-section-card{border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;margin-top:20px;box-shadow:0 1px 2px rgba(0,0,0,.45)}
      .cf-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .cf-field-card{border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px}
      .cf-title{font-weight:600;font-size:16px;margin:0 0 6px 0}
      .cf-summary{font-size:13px;margin:0}
      .cf-label{font-size:14px;display:block;margin-bottom:6px}
      .cf-help{font-size:12px;margin-top:6px}
      .cf-ul{list-style:disc;padding-left:20px;margin:0}
      .cf-ul li{margin:4px 0}
      .cf-paras p{margin:8px 0;line-height:1.6}
      @media(min-width:768px){.cf-section-card{padding:28px}.cf-grid{gap:20px}.cf-field-card{padding:20px}}
    </style>

@endonce

@php
    $shippingMeta = [];
    if (isset($order)) {
        $rawMeta = $order->meta ?? [];
        $metaArr = is_array($rawMeta) ? $rawMeta : (json_decode($rawMeta ?? '[]', true) ?: []);
        $shippingMeta = $metaArr['shipping'] ?? [];
    }
@endphp

@php
    $consultationNotesTemplate = <<<'TEXT'
Use a structured approach for example SOAP or encounter based

S Subjective presenting complaint history medicines allergies
O Objective observations exam findings investigations
A Assessment working diagnosis differentials risk stratification
P Plan treatment prescriptions referrals safety netting follow up

Important safety information

Pancreatitis (inflammation of the pancreas) is a possible side effect with GLP-1 receptor agonists and dual GLP-1/GIP receptor agonists. In rare reports this can have serious or fatal outcomes.

Seek urgent medical attention if you experience severe, persistent abdominal pain that may radiate to your back and may be accompanied by nausea and vomiting, as this may be a sign of pancreatitis.

Do not restart GLP-1 receptor agonist or GLP-1/GIP receptor agonist treatment if pancreatitis is confirmed.

Medication Review:
- New medication: (weight management)??
- Dose: once weekly injection, same day each week
- Storage: keep pen in fridge

Clinical Consultation:
- Weight management consultation
- First time using or current repeat patient?
- video call done new patient?

Patient Education:
- Injection technique: once weekly subcutaneous injection, same day each week
- Fluid intake: 2-3 litres daily to prevent constipation/diarrhoea
- Side effects discussed: initial nausea and headache (usually resolves), constipation or diarrhoea
- Rare side effect counselling: pancreatitis symptoms (severe abdominal pain radiating to back, high temperature, vomiting) - seek medical advice immediately
- Dosing schedule: start with current strength, reorder at end of week three via website, next dose. (either go down up or stay same in strengths)
- Can remain on current strength if effective weight loss achieved
-Pancreatitis (inflammation of the pancreas) is a possible side effect with GLP-1 receptor agonists and dual GLP-1/GIP receptor agonists. In rare reports this can have serious or fatal outcomes.

Seek urgent medical attention if you experience severe, persistent abdominal pain that may radiate to your back and may be accompanied by nausea and vomiting, as this may be a sign of pancreatitis.

Do not restart GLP-1 receptor agonist or GLP-1/GIP receptor agonist treatment if pancreatitis is confirmed.

Plan:
- Order dispatched today, delivery expected tomorrow
- Reorder via website at end of week three for next dose.
- Continue current strength if effective weight loss achieved
TEXT;

    $consultationNotesHelp = $consultationNotesTemplate;

    $consultationNotesValue = old('consultation_notes');

    $extractConsultationNotesValue = function ($raw) {
        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        if (is_array($raw) && !empty($raw)) {
            $last = end($raw);

            if (is_string($last) && trim($last) !== '') {
                return trim($last);
            }

            if (is_array($last)) {
                foreach (['note', 'text', 'value', 'content'] as $key) {
                    $v = $last[$key] ?? null;
                    if (is_string($v) && trim($v) !== '') {
                        return trim($v);
                    }
                }
            }

            foreach (array_reverse($raw) as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return trim($item);
                }
                if (is_array($item)) {
                    foreach (['note', 'text', 'value', 'content'] as $key) {
                        $v = $item[$key] ?? null;
                        if (is_string($v) && trim($v) !== '') {
                            return trim($v);
                        }
                    }
                }
            }
        }

        return null;
    };

    if ($consultationNotesValue === null || trim((string) $consultationNotesValue) === '') {
        $consultationNotesValue = null;

        if (!empty($oldData) && is_array($oldData)) {
            foreach ([
                'consultation_notes',
                'consultation-notes',
                'pharmacist_advice_notes',
                'pharmacist-advice-notes',
                'pharmacist_advice.consultation_notes',
                'pharmacist_advice.consultation-notes',
            ] as $k) {
                if (array_key_exists($k, $oldData)) {
                    $v = $extractConsultationNotesValue($oldData[$k]);
                    if ($v !== null && trim($v) !== '') {
                        $consultationNotesValue = $v;
                        break;
                    }
                }
            }
        }

        if (($consultationNotesValue === null || trim((string) $consultationNotesValue) === '') && isset($session)) {
            try {
                $sessionMeta = is_array($session->meta ?? null)
                    ? $session->meta
                    : (json_decode($session->meta ?? '[]', true) ?: []);

                foreach ([
                    'consultation_notes',
                    'consultation-notes',
                    'pharmacist_advice_notes',
                    'pharmacist-advice-notes',
                    'pharmacist_advice.consultation_notes',
                    'pharmacist_advice.consultation-notes',
                ] as $k) {
                    $v = data_get($sessionMeta, $k);
                    $v = $extractConsultationNotesValue($v);
                    if ($v !== null && trim($v) !== '') {
                        $consultationNotesValue = $v;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (($consultationNotesValue === null || trim((string) $consultationNotesValue) === '') && isset($order)) {
            try {
                $orderMeta = is_array($order->meta ?? null)
                    ? $order->meta
                    : (json_decode($order->meta ?? '[]', true) ?: []);

                foreach ([
                    'consultation_notes',
                    'consultation-notes',
                    'pharmacist_advice_notes',
                    'pharmacist-advice-notes',
                    'pharmacist_advice.consultation_notes',
                    'pharmacist_advice.consultation-notes',
                ] as $k) {
                    $v = data_get($orderMeta, $k);
                    $v = $extractConsultationNotesValue($v);
                    if ($v !== null && trim($v) !== '') {
                        $consultationNotesValue = $v;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($consultationNotesValue === null || trim((string) $consultationNotesValue) === '') {
            $consultationNotesValue = $consultationNotesTemplate;
        }
    }
@endphp

<div class="mb-4 flex flex-col gap-2">
    {{-- Consultation completion banner --}}
    {{-- Royal Mail banner when shipping meta indicates Click and Drop --}}
    @if (!empty($shippingMeta) && ($shippingMeta['carrier'] ?? null) === 'royal_mail_click_and_drop')
        <div class="inline-flex flex-wrap items-center gap-2 rounded-md border border-sky-300 bg-sky-50 px-3 py-2 text-sm text-sky-900">
            <span class="font-medium">Royal Mail Click and Drop</span>

            @php
                $serviceName = $shippingMeta['service_name'] ?? $shippingMeta['service'] ?? null;
                $statusLabel = $shippingMeta['status_label'] ?? $shippingMeta['status'] ?? null;
                $tracking    = $shippingMeta['tracking'] ?? $shippingMeta['tracking_number'] ?? null;
            @endphp

            @if ($serviceName)
                <span class="text-xs text-sky-800">
                    Service
                    <span class="font-medium">{{ $serviceName }}</span>
                </span>
            @endif

            @if ($statusLabel)
                <span class="text-xs text-sky-800">
                    Status
                    <span class="font-medium">{{ $statusLabel }}</span>
                </span>
            @endif

            @if ($tracking)
                <span class="text-xs text-sky-800">
                    Tracking
                    <span class="font-mono">{{ $tracking }}</span>
                </span>
            @endif
        </div>
    @endif
</div>

<div class="cf-section-card">
    <div class="mb-4">
        <h3 class="cf-title">Complete consultation</h3>
        <p class="cf-summary">
            Review the patient and order details below then confirm you have completed all clinical checks and the record of supply.
        </p></br>
    </div>

    <div class="cf-grid">
        @if ($patient ?? null)
            <div class="cf-field-card">
                <div class="cf-label">Patient</div>
                <div class="text-sm">
                    <div>
                        {{ $patient->full_name ?? $patient->name ?? 'Unknown patient' }}
                    </div>
                    @if ($patient->email ?? null)
                        <div class="text-xs text-gray-500">
                            {{ $patient->email }}
                        </div>
                    @endif
                    @if ($patient->dob ?? null)
                        <div class="text-xs text-gray-500">
                            Date of birth
                            {{ \Illuminate\Support\Carbon::parse($patient->dob)->format('d M Y') }}
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($order ?? null)
            <div class="cf-field-card">
                <div class="text-sm space-y-1">
                    <div>
                        Reference
                        {{ $order->reference ?? $order->id }}
                    </div>
                    @php
                        $service = $order->service ?? null;
                        $treatment = $order->treatment ?? null;
                    @endphp
                    @if ($service || $treatment)
                        <div class="text-xs text-gray-500">
                            @if ($service)
                                Service
                                {{ $service->name ?? $service->title ?? $service->slug ?? '' }}
                            @endif
                            @if ($treatment)
                                 
                                Treatment
                                {{ $treatment->name ?? $treatment->title ?? $treatment->slug ?? '' }}
                            @endif
                        </div>
                    @endif
                    @if (isset($order->created_at))
                        <div class="text-xs text-gray-500">
                            Order created
                            {{ optional($order->created_at)->timezone('Europe/London')->format('d M Y H:i') }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

<div class="cf-section-card">
    <div class="mb-4">
        <h3 class="cf-title">Before you complete</h3>
        <p class="cf-summary">
            Once completed you will not normally edit the consultation further.
        </p>
    </div>
    <ul class="cf-ul text-sm">
        <li>Confirm that all consultation steps are saved including risk assessment pharmacist advice and record of supply</li>
        <li>Ensure the medication details batch and expiry and directions are correct</li>
        <li>Check that clinical notes reflect your final decision</li>
    </ul>
</div>

@if ($isReorder ?? false)
<div class="cf-section-card">
    <div class="mb-4">
        <h3 class="cf-title">Consultation notes</h3>
    </div>

    <div class="cf-field-card">
        <textarea
            id="consultation_notes"
            name="consultation_notes"
            rows="18"
            class="block w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm"
        >{{ $consultationNotesValue }}</textarea>
    </div>
</div>
@endif

<form
    id="consult-complete-form"
    method="post"
    action="{{ url('/admin/consultations/' . ($session->id ?? $session->getKey()) . '/complete') }}"
    class="hidden"
>
    @csrf
    <input type="hidden" name="confirm_complete" value="1">
    <input type="hidden" name="consultation_notes" id="consultation_notes_hidden" value="">
</form>
<script>
(function(){
  var visible = document.getElementById('consultation_notes');
  var hidden = document.getElementById('consultation_notes_hidden');
  var form = document.getElementById('consult-complete-form');

  console.log('[consultations.complete] loaded', {
    hasVisibleNotes: !!visible,
    hasHiddenNotes: !!hidden,
    hasCompleteForm: !!form,
    action: form ? form.getAttribute('action') : null,
    sessionId: @json($session->id ?? $session->getKey()),
  });

  if (!visible || !hidden || !form) {
    console.warn('[consultations.complete] missing expected elements', {
      hasVisibleNotes: !!visible,
      hasHiddenNotes: !!hidden,
      hasCompleteForm: !!form,
    });
    return;
  }

  function syncNotes(){
    hidden.value = visible.value || '';
    console.log('[consultations.complete] notes synced', {
      length: hidden.value.length,
      preview: hidden.value.slice(0, 80),
    });
  }

  visible.addEventListener('input', syncNotes);
  visible.addEventListener('change', syncNotes);
  form.addEventListener('submit', function(){
    syncNotes();
    console.log('[consultations.complete] complete form submit', {
      action: form.getAttribute('action'),
      confirmComplete: form.querySelector('[name="confirm_complete"]')?.value || null,
      consultationNotesLength: hidden.value.length,
    });
  });

  window.addEventListener('save-all-tabs:start', function(event){
    console.log('[consultations.complete] save-all-tabs:start', event.detail || null);
  });

  window.addEventListener('save-all-tabs:tab-start', function(event){
    console.log('[consultations.complete] save-all-tabs:tab-start', event.detail || null);
  });

  window.addEventListener('save-all-tabs:tab-loaded', function(event){
    console.log('[consultations.complete] save-all-tabs:tab-loaded', event.detail || null);
  });

  window.addEventListener('save-all-tabs:tab-submit', function(event){
    console.log('[consultations.complete] save-all-tabs:tab-submit', event.detail || null);
  });

  window.addEventListener('save-all-tabs:tab-done', function(event){
    console.log('[consultations.complete] save-all-tabs:tab-done', event.detail || null);
  });

  window.addEventListener('save-all-tabs:done', function(event){
    console.log('[consultations.complete] save-all-tabs:done', event.detail || null);
  });

  window.addEventListener('save-all-tabs:error', function(event){
    console.error('[consultations.complete] save-all-tabs:error', event.detail || null);
  });

  syncNotes();
})();
</script>
