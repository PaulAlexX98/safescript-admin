<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Private Prescription</title>
  <style>
    @page { margin: 22mm 18mm; }
    body { font-family: 'Times New Roman', Times, serif; color:#000; font-size:12px; }

    /* Header */
    .header {
      display:flex;
      align-items:center;
      justify-content:flex-start;
      margin-top:20px;
      margin-bottom:28px;
      border-bottom:2px solid #2faa3f;
      padding-bottom:10px;
    }
    .header img { height:38px; padding-bottom:8px; margin-right:20px; }
    .title { font-size:20px; font-weight:bold; color:#2faa3f; text-transform:uppercase; margin-bottom:0; }
    .meta  { font-size:10px; color:#444; margin-top:4px; }

    /* Panels & layout */
    .section-title { font-size:14px; font-weight:bold; color:#2faa3f; border-bottom:1px solid #2faa3f; margin-bottom:6px; padding-bottom:2px; }
    .panel { border:1px solid #cfcfcf; border-radius:6px; padding:10px 12px; margin-top:0; }
    .kv td { padding:4px 0; vertical-align:top; }
    .kv td:first-child { width:120px; font-weight:bold; }

    .two-col { width:100%; border-collapse:separate; border-spacing:14px 0; }
    .two-col td { width:50%; vertical-align:top; padding:0; }

    /* Items table */
    table.items { width:100%; border-collapse:collapse; margin-top:10px; }
    table.items th { background:#e8f3e8; color:#000; text-align:left; font-weight:bold; border:1px solid #d0d0d0; padding:6px 8px; }
    table.items td { border:1px solid #d0d0d0; padding:6px 8px; }
    .right { text-align:right; }
  </style>
</head>

<body>
  
  @php
    // Normalise incoming meta payloads so data_get works reliably in prod
    $meta = is_array($meta) ? $meta : (json_decode($meta ?? '[]', true) ?: []);
    if (isset($order) && isset($order->meta) && !is_array($order->meta)) {
        $order->meta = $__safeDecode($order->meta ?? []);
    }
  @endphp
  @php
      // Robust JSON decode that survives utf8 issues, objects and double-encoded payloads
      $__safeDecode = function ($raw) {
          if (is_array($raw)) return $raw;
          if (is_object($raw)) {
              // cast stdClass / nested objects to array safely
              $asArray = json_decode(json_encode($raw), true);
              if (is_array($asArray)) return $asArray;
              return (array) $raw;
          }
          if (!is_string($raw) || $raw === '') return [];
          // straight decode
          $try = json_decode($raw, true);
          if (is_array($try)) return $try;
          // try utf8 encode
          $try = json_decode(@utf8_encode($raw), true);
          if (is_array($try)) return $try;
          // handle JSON string that itself contains JSON
          $inner = json_decode($raw, true);
          if (is_string($inner)) {
              $try = json_decode($inner, true);
              if (is_array($try)) return $try;
          }
          return [];
      };
  @endphp
  @php
      // Resolve "Date Provided" before rendering the header
      // Will pull from the saved Record of Supply / Clinical Notes response, then session meta, then request meta.
      if (!isset($dateProvided) || !is_string($dateProvided)) { $dateProvided = ''; }

      $fmtDate = function ($s) {
          $s = is_string($s) ? trim($s) : (is_null($s) ? '' : (string)$s);
          if ($s === '') return '';
          try { return \Carbon\Carbon::parse($s)->format('d/m/Y'); }
          catch (\Throwable $e) { return $s; }
      };
      $scalar = function ($v) {
          if (is_array($v)) {
              foreach (['value','raw','answer','label','text','date'] as $k) {
                  if (array_key_exists($k, $v) && $v[$k] !== '') return (string) $v[$k];
              }
              return '';
          }
          if (is_bool($v)) return $v ? 'Yes' : 'No';
          return (string) $v;
      };
      $pick = function (array $data, array $keys) use ($scalar) {
          foreach ($keys as $k) {
              $v = data_get($data, $k);
              if ($v !== null && $v !== '') {
                  $s = $scalar($v);
                  if ($s !== '') return $s;
              }
          }
          return '';
      };

      // Try from the latest saved Clinical Notes response in this consultation session
      try {
          $sid = data_get($meta ?? [], 'consultation_session_id')
              ?? data_get($meta ?? [], 'session_id')
              ?? data_get($meta ?? [], 'consultation_id')
              // fallback to order meta if present
              ?? data_get(($order->meta ?? []), 'consultation_session_id');

          $sess = $sid ? \App\Models\ConsultationSession::find($sid) : null;

          // if still not found, try to resolve by order id/reference
          if (!$sess) {
              try {
                  if (!empty($order?->id)) {
                      $sid2 = \App\Models\ConsultationSession::query()
                          ->where('order_id', $order->id)
                          ->value('id');
                      if ($sid2) $sess = \App\Models\ConsultationSession::find($sid2);
                  }
                  if (!$sess && !empty($ref ?? null)) {
                      $sid3 = \App\Models\ConsultationSession::query()
                          ->where('reference', $ref)
                          ->value('id');
                      if ($sid3) $sess = \App\Models\ConsultationSession::find($sid3);
                  }
              } catch (\Throwable $e) {}
          }

          // normalise session meta shape
          if ($sess && isset($sess->meta) && !is_array($sess->meta)) {
              $sess->meta = $__safeDecode($sess->meta ?? []);
          }

          if ($sess) {
              // Prefer a response that posted with __step_slug = record-of-supply, else any clinical_notes latest
              $resp = \App\Models\ConsultationFormResponse::query()
                  ->where('consultation_session_id', $sess->id)
                  ->where(function ($q) {
                      $q->where('data->__step_slug', 'record-of-supply')
                        ->orWhere('form_type', 'clinical_notes');
                  })
                  ->latest('id')
                  ->first();

              if ($resp) {
                  $raw = $resp->data ?? [];
                  $data = $__safeDecode($raw);
                  $answers = (array) data_get($data, 'data', []) + (array) data_get($data, 'answers', []) + (array) $data;

                  $dateProvided = $fmtDate($pick($answers, [
                      'date_provided','date-provided','date provided','dateProvided',
                      'date_of_supply','date-of-supply','supply_date',
                      'administration_date','admin_date','vaccination_date',
                      'dispense_date','issue_date','date'
                  ]));

                  // If still empty, scan all keys for something that *looks* like a date provided
                  if ($dateProvided === '') {
                      foreach ($answers as $k => $v) {
                          if (!is_string($k)) continue;
                          if (!preg_match('~(date\s*provided|provided\s*date|date[_\- ]of[_\- ]supply|supply[_\- ]date|administration[_\- ]date|admin[_\- ]date|vaccination[_\- ]date|dispense[_\- ]date|issue[_\- ]date|ros[_\- ]date)~i', $k)) {
                              continue;
                          }
                          $probe = $fmtDate($scalar($v));
                          if ($probe !== '') { $dateProvided = $probe; break; }
                      }
                  }
              }

              // Fallback to session meta
              if ($dateProvided === '' && isset($sess->meta)) {
                  $smeta = is_array($sess->meta) ? $sess->meta : (json_decode($sess->meta ?? '[]', true) ?: []);
                  $dateProvided = $fmtDate($pick($smeta, [
                      'date_provided','date_of_supply','date-of-supply','supply_date',
                      'administration_date','admin_date','vaccination_date','date'
                  ]));
              }
          }
      } catch (\Throwable $e) { /* ignore and continue to meta fallback */ }

      // Final fallback to request-level meta
      if ($dateProvided === '') {
          $dateProvided = $fmtDate($pick(($meta ?? []), [
              'date_provided','date_of_supply','date-of-supply','supply_date',
              'administration_date','admin_date','vaccination_date','date'
          ]));
      }
  @endphp

  <div class="header">
    @if(isset($pharmacy['logo']) && is_file($pharmacy['logo']))
      <img src="{{ $pharmacy['logo'] }}" alt="Pharmacy Express">
    @endif
    <div>
      <div class="title">Private Prescription</div>
      <div class="meta">Reference: {{ $ref }} | Date: {{ $dateProvided ?: now()->format('d/m/Y') }}</div>
    </div>
  </div>

  <div>
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
              <tr><td>DOB:</td><td>
                @if(!empty($patient['dob']))
                  {{ \Carbon\Carbon::parse($patient['dob'])->format('d/m/Y') }}
                @else
                  —
                @endif
              </td></tr>
              <tr><td>Address:</td><td>{{ $patient['address'] ?: '—' }}</td></tr>
              <tr><td>Contact:</td><td>{{ $patient['email'] ?: '—' }} @if(!empty($patient['phone'])) | {{ $patient['phone'] }} @endif</td></tr>
            </table>
          </div>
        </td>
      </tr>
    </table>
  </div>

  @php

      // Locate the ConsultationSession if present
      $sid = data_get($meta ?? [], 'consultation_session_id')
          ?? data_get($meta ?? [], 'session_id')
          ?? data_get($meta ?? [], 'consultation_id')
          ?? data_get(($order->meta ?? []), 'consultation_session_id');

      $sess = null;
      try { if ($sid) { $sess = \App\Models\ConsultationSession::find($sid); } } catch (\Throwable $e) {}

      // Fallback lookups by order
      if (!$sess) {
          try {
              if (!empty($order?->id)) {
                  $sid2 = \App\Models\ConsultationSession::query()
                      ->where('order_id', $order->id)
                      ->value('id');
                  if ($sid2) $sess = \App\Models\ConsultationSession::find($sid2);
              }
              if (!$sess && !empty($ref ?? null)) {
                  $sid3 = \App\Models\ConsultationSession::query()
                      ->where('reference', $ref)
                      ->value('id');
                  if ($sid3) $sess = \App\Models\ConsultationSession::find($sid3);
              }
          } catch (\Throwable $e) {}
      }

      // Ensure session meta is an array
      if ($sess && isset($sess->meta) && !is_array($sess->meta)) {
          $sess->meta = $__safeDecode($sess->meta ?? []);
      }

      // Helper for service/treatment slugs
      $slugify = function ($v) { return $v ? \Illuminate\Support\Str::slug((string) $v) : null; };
      $svcSlug = $slugify($sess->service_slug ?? $sess->service ?? data_get($meta ?? [], 'service_slug'));
      $trtSlug = $slugify($sess->treatment_slug ?? data_get($meta ?? [], 'treatment_slug'));

      // Prefer the related order for items
      $order = $order ?? null;
      try { if (!$order && $sess && method_exists($sess, 'order')) { $order = $sess->order; } } catch (\Throwable $e) {}
      $lineItems = is_array($items ?? null) ? $items : (is_array($order->items ?? null) ? $order->items : []);
      $first = $lineItems[0] ?? ($items[0] ?? []);

      // ---- Pull pharmacist declaration answers (name, GPhC, signature) ----
      $declName = null; $declGphc = null; $declSig = null;

      try {
          $declFormId = null;

          // Prefer template placed on the session
          if ($sess && isset($sess->templates)) {
              $tpl = \Illuminate\Support\Arr::get($sess->templates, 'declaration')
                  ?? \Illuminate\Support\Arr::get($sess->templates, 'pharmacist_declaration')
                  ?? \Illuminate\Support\Arr::get($sess->templates, 'pharmacist-declaration');
              if ($tpl) {
                  if (is_array($tpl))       $declFormId = $tpl['id'] ?? $tpl['form_id'] ?? null;
                  elseif (is_numeric($tpl)) $declFormId = (int) $tpl;
              }
          }

          // Fallback: latest active 'declaration' ClinicForm for this service/treatment
          if (!$declFormId) {
              $base = \App\Models\ClinicForm::query()
                  ->where('form_type', 'declaration')
                  ->where('is_active', 1)
                  ->orderByDesc('version')->orderByDesc('id');

              $declForm = null;
              if ($svcSlug && $trtSlug) {
                  $declForm = (clone $base)->where('service_slug', $svcSlug)->where('treatment_slug', $trtSlug)->first();
              }
              if (!$declForm && $svcSlug) {
                  $declForm = (clone $base)->where('service_slug', $svcSlug)
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })->first();
              }
              if (!$declForm && $svcSlug) {
                  $svc = \App\Models\Service::query()->where('slug', $svcSlug)->first();
                  if ($svc && $svc->pharmacistDeclarationForm) $declForm = $svc->pharmacistDeclarationForm;
              }
              if (!$declForm) {
                  $declForm = (clone $base)
                      ->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                      ->first();
              }
              if ($declForm) $declFormId = $declForm->id ?? null;
          }

          if ($declFormId && $sess) {
              $resp = \App\Models\ConsultationFormResponse::query()
                  ->where('consultation_session_id', $sess->id)
                  ->where('clinic_form_id', $declFormId)
                  ->latest('id')
                  ->first();

              $data = $__safeDecode($resp?->data ?? []);

              $pick = function (array $keys) use ($data) {
                  foreach ($keys as $k) {
                      $v = data_get($data, $k);
                      if (is_array($v)) {
                          if (array_key_exists('value', $v)) {
                              $v = $v['value'];
                          } elseif (array_key_exists('raw', $v)) {
                              $v = $v['raw'];
                          } elseif (array_key_exists('answer', $v)) {
                              $v = $v['answer'];
                          }
                      }
                      // accept numeric 0 and other scalars, but reject true/false
                      if (is_scalar($v) && !is_bool($v)) {
                          $s = (string) $v;
                          if ($s !== '') return $s;
                      }
                  }
                  return null;
              };

              $declName = $pick([
                  'pharmacist_name','pharmacist-name','name','pharmacist','full_name','pharmacist_full_name'
              ]);
              $declGphc = $pick([
                  'gphc','gphc_number','gphc-number','gphc_no','gphcNo','gphcno','gphc_num',
                  'registration','registration_number','reg_number','reg_no','reg',
                  'pharmacist_gphc_number','pharmacist_number','pharmacist_registration_number','pharmacist_reg_number'
              ]);
              $declSig  = $pick(['signature','pharmacist_signature','sig','signature.data']);

              // Fuzzy fallback: if still empty, grab the first scalar value whose key mentions gphc or registration
              if (!$declGphc) {
                  foreach ((array) $data as $k => $v) {
                      if (!is_string($k)) continue;
                      if (!preg_match('/\b(gphc|reg(?:istration)?)\b/i', $k)) continue;
                      if (is_array($v) && array_key_exists('value', $v)) { $v = $v['value']; }
                      if (is_scalar($v) && (string) $v !== '') { $declGphc = (string) $v; break; }
                  }
              }

              if (!$declName) {
                  $declName = data_get($pharmacist ?? [], 'name')
                      ?: data_get($meta ?? [], 'pharmacist.name');
              }
              if (!$declGphc) {
                  $declGphc = data_get($pharmacist ?? [], 'gphc')
                      ?: data_get($pharmacist ?? [], 'gphc_number')
                      ?: data_get($pharmacist ?? [], 'gphc-number')
                      ?: data_get($pharmacist ?? [], 'registration_number')
                      ?: data_get($pharmacist ?? [], 'reg_number')
                      ?: data_get($meta ?? [], 'pharmacist.gphc')
                      ?: data_get($meta ?? [], 'pharmacist.gphc_number')
                      ?: data_get($meta ?? [], 'pharmacist.registration_number');
                  if (is_numeric($declGphc)) { $declGphc = (string) $declGphc; }
              }
          }
      } catch (\Throwable $e) {}

      // ---- Pull "Other clinical notes" from the saved Clinical Notes (Record of Supply) form ----
      $extraNotes = null;
      // also collect admin site and route from the same form
      $adminSite = '';
      $adminRoute = '';
      // batch and expiry from clinical notes
      $batchNumber = '';
      $expiryDate  = '';
      // date provided from clinical notes (eg date_of_supply)

      // pretty print any saved answer shape
      $__prettyAns = function ($v) {
          if (is_array($v)) {
              if (array_key_exists('label', $v)) return (string) $v['label'];
              if (array_key_exists('value', $v)) return (string) $v['value'];
              if (array_key_exists('answer', $v)) return (string) $v['answer'];
              if (array_key_exists('raw', $v)) return (string) $v['raw'];
          }
          if (is_bool($v)) return $v ? 'Yes' : 'No';
          return trim((string) $v);
      };

      $__fmtDate = function ($s) {
          $s = trim((string) $s);
          if ($s === '') return '';
          try { return \Carbon\Carbon::parse($s)->format('d/m/Y'); }
          catch (\Throwable $e) { return $s; }
      };

      // Helper to robustly find a date provided field anywhere in saved data
      $__findDateProvided = function ($arr) use (&$__findDateProvided, $__prettyAns, $__fmtDate) {
          if (!is_array($arr)) return '';
          foreach ($arr as $k => $v) {
              // if associative and has nested data, search inside first
              if (is_array($v) && !\Illuminate\Support\Arr::isAssoc($v)) {
                  // list
                  foreach ($v as $vv) {
                      $found = $__findDateProvided($vv);
                      if ($found !== '') return $found;
                  }
              } elseif (is_array($v) && \Illuminate\Support\Arr::isAssoc($v)) {
                  // common wrappers
                  $inner = $v['value'] ?? $v['raw'] ?? $v['answer'] ?? $v['date'] ?? null;
                  if ($inner !== null) {
                      $pretty = $__prettyAns($inner);
                      $fmt = $__fmtDate($pretty);
                      if ($fmt !== '') return $fmt;
                  }
                  $found = $__findDateProvided($v);
                  if ($found !== '') return $found;
              }

              $keyStr = is_string($k) ? $k : '';
              // Match a wide range of possible keys for date provided
              if ($keyStr !== '' && preg_match('~(date\s*provided|provided\s*date|date[_\- ]of[_\- ]supply|supply[_\- ]date|administration[_\- ]date|admin[_\- ]date|vaccination[_\- ]date|dispense[_\- ]date|issue[_\- ]date|ros[_\- ]date)~i', $keyStr)) {
                  $pretty = $__prettyAns($v);
                  $fmt = $__fmtDate($pretty);
                  if ($fmt !== '') return $fmt;
              }
          }
          return '';
      };

      $__pickFrom = function (array $data, array $keys) use ($__prettyAns) {
          foreach ($keys as $k) {
              $v = data_get($data, $k);
              if ($v !== null && $v !== '') {
                  $s = $__prettyAns($v);
                  if ($s !== '') return $s;
              }
          }
          return '';
      };
      try {
          $rosFormId = null;

          if ($sess && isset($sess->templates)) {
              $tpl = \Illuminate\Support\Arr::get($sess->templates, 'clinical_notes')
                  ?? \Illuminate\Support\Arr::get($sess->templates, 'record_of_supply')
                  ?? \Illuminate\Support\Arr::get($sess->templates, 'supply')
                  ?? \Illuminate\Support\Arr::get($sess->templates, 'clinicalNotes');
              if ($tpl) {
                  if (is_array($tpl))       $rosFormId = $tpl['id'] ?? $tpl['form_id'] ?? null;
                  elseif (is_numeric($tpl)) $rosFormId = (int) $tpl;
              }
          }

          if (!$rosFormId) {
              $base = \App\Models\ClinicForm::query()
                  ->where('form_type', 'clinical_notes')
                  ->where('is_active', 1)
                  ->orderByDesc('version')->orderByDesc('id');

              $rosForm = null;
              if ($svcSlug && $trtSlug) {
                  $rosForm = (clone $base)->where('service_slug', $svcSlug)->where('treatment_slug', $trtSlug)->first();
              }
              if (!$rosForm && $svcSlug) {
                  $rosForm = (clone $base)->where('service_slug', $svcSlug)
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })->first();
              }
              if (!$rosForm && $svcSlug) {
                  $svc = \App\Models\Service::query()->where('slug', $svcSlug)->first();
                  if ($svc && $svc->clinicalNotesForm) $rosForm = $svc->clinicalNotesForm;
              }
              if (!$rosForm) {
                  $rosForm = (clone $base)
                      ->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                      ->first();
              }
              if ($rosForm) $rosFormId = $rosForm->id ?? null;
          }

          if ($rosFormId && $sess) {
              $resp = \App\Models\ConsultationFormResponse::query()
                  ->where('consultation_session_id', $sess->id)
                  ->where('clinic_form_id', $rosFormId)
                  ->latest('id')
                  ->first();

              $data = $__safeDecode($resp?->data ?? []);
              $extraNotes = (string) (
                  data_get($data, 'other_clinical_notes')
                  ?? data_get($data, 'other-clinical-notes')
                  ?? data_get($data, 'other clinical notes')
                  ?? data_get($data, 'clinical_notes')
                  ?? data_get($data, 'ros.other_clinical_notes')
                  ?? ''
              );

              // pull site and route from the same saved data
              if ($adminSite === '') {
                  $adminSite = $__pickFrom($data, [
                      'administration_site','admin_site','site','injection_site','vaccination_site',
                      'administration-site','admin-site','injection-site','vaccination-site',
                  ]);
              }
              if ($adminRoute === '') {
                  $adminRoute = $__pickFrom($data, [
                      'administration_route','admin_route','route','vaccination_route','injection_route',
                      'administration-route','admin-route','vaccination-route','injection-route',
                  ]);
              }
              if ($batchNumber === '') {
                  $batchNumber = $__pickFrom($data, [
                      'batch_number','batch','batch_no','batchNo','lot','lot_number','lot_no','lotNo',
                      'batch-number','batch-no','lot-number','lot-no',
                  ]);
              }
              if ($expiryDate === '') {
                  $expiryDate = $__fmtDate($__pickFrom($data, [
                      'expiry_date','expiry','exp_date','exp','expiryDate','expiry_date_input',
                      'expiry-date','exp-date',
                  ]));
              }
              // resolve supplied date / date provided from the same saved data
              if ($dateProvided === '') {
                  $dateProvided = $__fmtDate($__pickFrom($data, [
                      'dateProvided','date provided','date_provided','date-provided','provided_date',
                      'date-of-provided','date-of-supply','date_of_supply','supply_date','dispense_date','issue_date','administration_date','admin_date','vaccination_date','date'
                  ]));
                  if ($dateProvided === '') {
                      $dateProvided = $__findDateProvided($data);
                  }
              }
          }
      } catch (\Throwable $e) {}

      // If still empty, try session meta directly
      if (($extraNotes === null || $extraNotes === '') && isset($sess) && isset($sess->meta)) {
          try {
              $smeta = is_array($sess->meta) ? $sess->meta : ($__safeDecode($sess->meta ?? []) ?: []);
              $val = data_get($smeta, 'other_clinical_notes')
                  ?? data_get($smeta, 'other-clinical-notes')
                  ?? data_get($smeta, 'other clinical notes')
                  ?? data_get($smeta, 'clinical_notes')
                  ?? data_get($smeta, 'ros.other_clinical_notes');
              if (is_string($val) && trim($val) !== '') $extraNotes = (string) $val;
              if ($adminSite === '') {
                  $v = data_get($smeta, 'administration_site') ?? data_get($smeta, 'admin_site') ?? data_get($smeta, 'site')
                      ?? data_get($smeta, 'administration-site') ?? data_get($smeta, 'admin-site');
                  if (is_string($v) && trim($v) !== '') { $adminSite = trim($v); }
              }
              if ($adminRoute === '') {
                  $v = data_get($smeta, 'administration_route') ?? data_get($smeta, 'admin_route') ?? data_get($smeta, 'route')
                      ?? data_get($smeta, 'administration-route') ?? data_get($smeta, 'admin-route');
                  if (is_string($v) && trim($v) !== '') { $adminRoute = trim($v); }
              }
              if ($batchNumber === '') {
                  $v = data_get($smeta, 'batch_number') ?? data_get($smeta, 'batch') ?? data_get($smeta, 'lot')
                      ?? data_get($smeta, 'batch-number') ?? data_get($smeta, 'lot-number');
                  if (is_string($v) && trim($v) !== '') { $batchNumber = trim($v); }
              }
              if ($expiryDate === '') {
                  $v = data_get($smeta, 'expiry_date') ?? data_get($smeta, 'expiry') ?? data_get($smeta, 'exp_date')
                      ?? data_get($smeta, 'expiry-date') ?? data_get($smeta, 'exp-date');
                  if ($v !== null && $v !== '') { $expiryDate = $__fmtDate($v); }
              }
              if ($dateProvided === '') {
                  $v = data_get($smeta, 'date_provided')
                      ?? data_get($smeta, 'date-provided')
                      ?? data_get($smeta, 'provided_date')
                      ?? data_get($smeta, 'date_of_supply')
                      ?? data_get($smeta, 'date-of-supply')
                      ?? data_get($smeta, 'supply_date')
                      ?? data_get($smeta, 'administration_date')
                      ?? data_get($smeta, 'admin_date')
                      ?? data_get($smeta, 'vaccination_date')
                      ?? data_get($smeta, 'date');
                  if ($v !== null && $v !== '') { $dateProvided = $__fmtDate($v); }
              }
              if ($dateProvided === '') {
                  $dateProvided = $__findDateProvided($smeta);
              }
          } catch (\Throwable $e) {}
      }

      // Broader fallback: scan a few recent form responses in this session for the key
      if (($extraNotes === null || $extraNotes === '') && isset($sess)) {
          try {
              $resps = \App\Models\ConsultationFormResponse::query()
                  ->where('consultation_session_id', $sess->id)
                  ->latest('id')
                  ->limit(6)
                  ->get();
              foreach ($resps as $r) {
                  $d = $__safeDecode($r->data ?? []);
                  $val = data_get($d, 'other_clinical_notes');
                  if (is_string($val) && trim($val) !== '') { $extraNotes = (string) $val; break; }
                  if ($adminSite === '') {
                      $vv = data_get($d, 'administration_site') ?? data_get($d, 'admin_site') ?? data_get($d, 'site')
                          ?? data_get($d, 'administration-site') ?? data_get($d, 'admin-site');
                      $vv = $__prettyAns($vv);
                      if ($vv !== '') { $adminSite = $vv; }
                  }
                  if ($adminRoute === '') {
                      $vv = data_get($d, 'administration_route') ?? data_get($d, 'admin_route') ?? data_get($d, 'route')
                          ?? data_get($d, 'administration-route') ?? data_get($d, 'admin-route');
                      $vv = $__prettyAns($vv);
                      if ($vv !== '') { $adminRoute = $vv; }
                  }
                  if ($batchNumber === '') {
                      $vv = $__pickFrom($d, ['batch_number','batch','batch_no','batchNo','lot','lot_number','lot_no','lotNo','batch-number','batch-no','lot-number','lot-no']);
                      if ($vv !== '') { $batchNumber = $vv; }
                  }
                  if ($expiryDate === '') {
                      $vv = $__pickFrom($d, ['expiry_date','expiry','exp_date','exp','expiryDate','expiry_date_input','expiry-date','exp-date']);
                      $vv = $__fmtDate($vv);
                      if ($vv !== '') { $expiryDate = $vv; }
                  }
                  if ($dateProvided === '') {
                      $vv = $__pickFrom($d, [
                          'date_provided','date-provided','provided_date','date-of-supply','date_of_supply','supply_date',
                          'administration_date','admin_date','vaccination_date','date'
                      ]);
                      $vv = $__fmtDate($vv);
                      if ($vv !== '') { $dateProvided = $vv; }
                  }
                  if ($dateProvided === '') {
                      $dateProvided = $__findDateProvided($d);
                  }
              }
          } catch (\Throwable $e) {}
      }

      // Final defensive fallback for notes (order/meta level)
      if ($extraNotes === null || $extraNotes === '') {
          $extraNotes = data_get($meta ?? [], 'other_clinical_notes')
              ?? data_get($meta ?? [], 'other-clinical-notes')
              ?? data_get($meta ?? [], 'other clinical notes')
              ?? data_get($meta ?? [], 'clinical_notes')
              ?? data_get($meta ?? [], 'notes')
              ?? data_get($meta ?? [], 'ros.notes')
              ?? '';
      }
      if ($adminSite === '') {
          $adminSite = (string) (
              data_get($meta ?? [], 'administration_site') ?? data_get($meta ?? [], 'admin_site') ?? data_get($meta ?? [], 'site')
              ?? data_get($meta ?? [], 'administration-site') ?? data_get($meta ?? [], 'admin-site') ?? ''
          );
      }
      if ($adminRoute === '') {
          $adminRoute = (string) (
              data_get($meta ?? [], 'administration_route') ?? data_get($meta ?? [], 'admin_route') ?? data_get($meta ?? [], 'route')
              ?? data_get($meta ?? [], 'administration-route') ?? data_get($meta ?? [], 'admin-route') ?? ''
          );
      }
      if ($batchNumber === '') {
          $batchNumber = (string) (
              data_get($meta ?? [], 'batch_number') ?? data_get($meta ?? [], 'batch') ?? data_get($meta ?? [], 'lot')
              ?? data_get($meta ?? [], 'batch-number') ?? data_get($meta ?? [], 'lot-number') ?? ''
          );
      }
      if ($expiryDate === '') {
          $expiryDate = $__fmtDate(
              data_get($meta ?? [], 'expiry_date') ?? data_get($meta ?? [], 'expiry') ?? data_get($meta ?? [], 'exp_date')
              ?? data_get($meta ?? [], 'expiry-date') ?? data_get($meta ?? [], 'exp-date')
          );
          
      }
      if ($dateProvided === '') {
          $dateProvided = $__fmtDate(
              data_get($meta ?? [], 'date_provided')
              ?? data_get($meta ?? [], 'date-provided')
              ?? data_get($meta ?? [], 'provided_date')
              ?? data_get($meta ?? [], 'date_of_supply')
              ?? data_get($meta ?? [], 'date-of-supply')
              ?? data_get($meta ?? [], 'supply_date')
              ?? data_get($meta ?? [], 'administration_date')
              ?? data_get($meta ?? [], 'admin_date')
              ?? data_get($meta ?? [], 'vaccination_date')
              ?? data_get($meta ?? [], 'date')
          );
      }
  @endphp

  <div class="panel" style="margin-top:16px;">
    <div class="section-title">Medicine Prescribed</div>

    @php
      /**
       * Goal: render exactly what was saved in the Record of Supply / Clinical Notes form,
       * in the same order, without hardcoding any field names or trying to infer groups.
       *
       * We prefer list-shaped data (answers[] or data[]) where each row contains
       * label|question|key and value|answer|raw|selected.
       * If only associative maps exist, we fall back to the matched schema order to build rows.
       */

      // Helper to coerce any answer value into a printable string
      $rosHuman = function ($v) {
          if (is_bool($v)) return $v ? 'Yes' : 'No';
          if (is_array($v)) {
              // common shapes
              if (array_key_exists('label', $v)) return (string) $v['label'];
              if (array_key_exists('value', $v)) return (string) $v['value'];
              if (array_key_exists('answer', $v)) return (string) $v['answer'];
              if (array_key_exists('raw', $v))   return (string) $v['raw'];
              if (isset($v['selected']) && is_array($v['selected'])) {
                  $sel = $v['selected'];
                  return (string) ($sel['label'] ?? $sel['value'] ?? '');
              }
              // array of scalars or objects
              $parts = [];
              $list  = \Illuminate\Support\Arr::isAssoc($v) ? [$v] : $v;
              foreach ($list as $x) {
                  if (is_scalar($x)) { $parts[] = (string) $x; continue; }
                  if (is_array($x)) {
                      if (array_key_exists('label', $x)) { $parts[] = (string) $x['label']; continue; }
                      if (array_key_exists('value', $x)) { $parts[] = (string) $x['value']; continue; }
                      if (array_key_exists('answer', $x)) { $parts[] = (string) $x['answer']; continue; }
                      if (array_key_exists('raw', $x))   { $parts[] = (string) $x['raw']; continue; }
                  }
              }
              $parts = array_values(array_filter($parts, fn($s) => trim((string) $s) !== ''));
              return implode(', ', $parts);
          }
          return trim((string) $v);
      };

      // --- helpers for label prettification and schema label lookup ---
      $__slug = function ($v) { return $v ? \Illuminate\Support\Str::slug((string) $v) : null; };
      $__prettify = function ($s) {
          $s = trim((string) $s);
          if ($s === '') return '';
          $s = str_replace(['_', '-'], ' ', $s);
          $s = preg_replace('/\s+/', ' ', $s);
          return \Illuminate\Support\Str::title($s);
      };

      $rosRows = [];
      $decoded = [];

      try {
          // Ensure we have the latest Clinical Notes response for this session
          if (!isset($sess)) {
              $sid = data_get($meta ?? [], 'consultation_session_id')
                  ?? data_get($meta ?? [], 'session_id')
                  ?? data_get($meta ?? [], 'consultation_id');
              $sess = $sid ? \App\Models\ConsultationSession::find($sid) : null;
          }

          if (!isset($rosFormId) || !$rosFormId) {
              // Resolve a clinical_notes form id matching this session (service/treatment aware)
              $slug = fn($v) => $v ? \Illuminate\Support\Str::slug((string) $v) : null;
              $svcSlug = isset($sess) ? $slug($sess->service_slug ?? $sess->service ?? data_get($meta ?? [], 'service_slug')) : null;
              $trtSlug = isset($sess) ? $slug($sess->treatment_slug ?? data_get($meta ?? [], 'treatment_slug')) : null;

              $base = \App\Models\ClinicForm::query()
                  ->where('form_type', 'clinical_notes')
                  ->where('is_active', 1)
                  ->orderByDesc('version')->orderByDesc('id');

              $rosForm = null;
              if ($svcSlug && $trtSlug) {
                  $rosForm = (clone $base)->where('service_slug', $svcSlug)->where('treatment_slug', $trtSlug)->first();
              }
              if (!$rosForm && $svcSlug) {
                  $rosForm = (clone $base)->where('service_slug', $svcSlug)
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })->first();
              }
              if (!$rosForm && $svcSlug) {
                  $svc = \App\Models\Service::query()->where('slug', $svcSlug)->first();
                  if ($svc && $svc->clinicalNotesForm) $rosForm = $svc->clinicalNotesForm;
              }
              if (!$rosForm) {
                  $rosForm = (clone $base)
                      ->where(function ($q) { $q->whereNull('service_slug')->orWhere('service_slug', ''); })
                      ->where(function ($q) { $q->whereNull('treatment_slug')->orWhere('treatment_slug', ''); })
                      ->first();
              }
              $rosFormId = $rosForm->id ?? null;
          }

          // --- Build a key→label lookup from schema so we can swap machine keys for real questions ---
          $labelMap = [];
          if (!isset($rosForm) && isset($rosFormId)) {
              $rosForm = \App\Models\ClinicForm::find($rosFormId);
          }
          if (isset($rosForm)) {
              $schemaRaw = is_array($rosForm->schema) ? $rosForm->schema : ($__safeDecode($rosForm->schema ?? []) ?: []);
              if (is_array($schemaRaw) && !empty($schemaRaw)) {
                  // Support both block-based and sections[fields] shapes
                  if (array_key_exists('fields', $schemaRaw[0] ?? [])) {
                      foreach ($schemaRaw as $sec) {
                          foreach ((array)($sec['fields'] ?? []) as $f) {
                              $k = $f['key'] ?? null; $lab = $f['label'] ?? null;
                              if ($k && $lab) { $labelMap[$__slug($k)] = (string)$lab; }
                          }
                      }
                  } else {
                      foreach ($schemaRaw as $blk) {
                          $type = $blk['type'] ?? null;
                          if ($type === 'section') continue;
                          $d = (array)($blk['data'] ?? []);
                          $k = $d['key'] ?? null; $lab = $d['label'] ?? null;
                          if ($k && $lab) { $labelMap[$__slug($k)] = (string)$lab; }
                      }
                  }
              }
          }
          $__displayLabel = function ($label, $key = null) use ($labelMap, $__slug, $__prettify) {
              $lab = trim((string)($label ?? ''));
              $k = $key ? $__slug($key) : ($lab !== '' ? $__slug($lab) : null);
              if ($k && isset($labelMap[$k])) return (string) $labelMap[$k];
              // If looks like a machine key, prettify
              if ($lab === '' || preg_match('~^[a-z0-9]+([\-_][a-z0-9]+)+$~', $lab)) {
                  return $key ? $__prettify($key) : $__prettify($lab);
              }
              return $lab;
          };

          if (!isset($resp) || !$resp) {
              if (isset($sess)) {
                  $resp = \App\Models\ConsultationFormResponse::query()
                      ->where('consultation_session_id', $sess->id)
                      ->where(function ($q) use ($rosFormId) {
                          if ($rosFormId) $q->where('clinic_form_id', $rosFormId);
                          $q->orWhere('data->__step_slug', 'record-of-supply');
                      })
                      ->latest('id')
                      ->first();
              }
          }

          if (isset($resp) && $resp) {
              $raw = $resp->data ?? [];
              $decoded = $__safeDecode($raw);
          }

          // Candidate sources in order
          $candidates = [];
          foreach (['answers', 'data'] as $k) {
              $v = data_get($decoded, $k);
              if (is_array($v)) $candidates[] = $v;
          }
          if (empty($candidates) && is_array($decoded)) {
              $candidates[] = $decoded;
          }

          // 1) Prefer list-shaped rows
          foreach ($candidates as $cand) {
              $looksList = is_array($cand) && (array_key_exists(0, $cand) || array_keys($cand) === range(0, count($cand) - 1));
              if ($looksList) {
                  foreach ($cand as $row) {
                      if (!is_array($row)) continue;
                      $rawLabel = $row['label'] ?? $row['question'] ?? null;
                      $rawKey   = $row['key'] ?? null;
                      $val = $row['value'] ?? $row['answer'] ?? ($row['selected']['label'] ?? $row['selected']['value'] ?? null) ?? ($row['raw'] ?? null);
                      $dispLabel = $__displayLabel($rawLabel, $rawKey);
                      if ($dispLabel !== null && $dispLabel !== '') {
                          $rosRows[] = [ (string) $dispLabel, $rosHuman($val) ];
                      }
                  }
                  break;
              }
          }

          // 2.5) Recursive flatten fallback for associative shapes
          if (empty($rosRows) && !empty($decoded)) {
              $skipKey = function($k){
                  if (!is_string($k)) return true;
                  if ($k === '' || $k[0] === '_') return true;
                  $bad = [
                      'form_type','__step_slug','service','treatment','_token','schema','version','id',
                      'clinic_form_id','consultation_session_id','created_at','updated_at','meta'
                  ];
                  return in_array($k, $bad, true);
              };
              $flatten = function($arr, $prefix = '') use (&$flatten, $skipKey) {
                  $out = [];
                  if (!is_array($arr)) return $out;
                  foreach ($arr as $k => $v) {
                      $kstr = is_string($k) ? $k : (string)$k;
                      if ($skipKey($kstr)) continue;
                      $key = $prefix ? $prefix.'.'.$kstr : $kstr;
                      if (is_array($v)) {
                          // common value wrappers
                          foreach (['value','label','answer','raw'] as $inner) {
                              if (array_key_exists($inner, $v)) { $v = $v[$inner]; break; }
                          }
                      }
                      if (is_array($v)) {
                          $out += $flatten($v, $key);
                      } elseif (is_bool($v)) {
                          $out[$key] = $v ? 'Yes' : 'No';
                      } else {
                          $sv = trim((string)$v);
                          if ($sv !== '') $out[$key] = $sv;
                      }
                  }
                  return $out;
              };
              $flat = $flatten($decoded);
              if (empty($flat)) {
                  $flat = $flatten((array) data_get($decoded, 'data', []));
              }
              if (empty($flat)) {
                  $flat = $flatten((array) data_get($decoded, 'answers', []));
              }
              foreach ($flat as $k => $v) {
                  $rosRows[] = [ (string) $__displayLabel(null, (string)$k), $rosHuman($v) ];
              }
          }

          // 2) Fallback: build from associative maps using schema order and index suffixes
          if (empty($rosRows)) {
              $assoc = (array) $decoded + (array) data_get($decoded, 'data', []) + (array) data_get($decoded, 'answers', []);

              $assoc = array_filter($assoc, function ($v, $k) {
                  if (!is_string($k)) return true;
                  if (str_starts_with($k, '_')) return false;
                  return !in_array($k, ['form_type','__step_slug','service','treatment','_token'], true);
              }, ARRAY_FILTER_USE_BOTH);

              $byIndex = [];
              foreach ($assoc as $k => $v) {
                  if (!is_string($k)) continue;
                  $normKey = str_replace('_', '-', $k);
                  if (preg_match('~^(.*?)-(\d+)$~', $normKey, $m)) {
                      $base = $m[1];
                      $idx  = (int) $m[2];
                  } else {
                      $base = $normKey;
                      $idx  = 1;
                  }
                  if (!isset($byIndex[$idx])) $byIndex[$idx] = [];
                  $byIndex[$idx][$base] = $v;
              }
              ksort($byIndex);

              // load schema for order if available
              $schemaFields = [];
              if (!isset($rosForm) && isset($rosFormId)) {
                  $rosForm = \App\Models\ClinicForm::find($rosFormId);
              }
              if (isset($rosForm)) {
                  $schemaRaw = is_array($rosForm->schema) ? $rosForm->schema : ($__safeDecode($rosForm->schema ?? []) ?: []);
                  if (is_array($schemaRaw) && !empty($schemaRaw)) {
                      if (array_key_exists('fields', $schemaRaw[0] ?? [])) {
                          foreach ($schemaRaw as $sec) {
                              foreach (($sec['fields'] ?? []) as $f) $schemaFields[] = $f;
                          }
                      } else {
                          foreach ($schemaRaw as $blk) {
                              $type = $blk['type'] ?? null;
                              if ($type === 'section') continue;
                              $schemaFields[] = (array) ($blk['data'] ?? []);
                          }
                      }
                  }
              }

              $renderGroup = function(array $group, array $fields) use (&$rosRows, $rosHuman, $assoc, $__displayLabel) {
                  // helper to fetch by key or label slug
                  $getFromGroup = function (array $group, string $key, ?string $label = null) use ($assoc) {
                      $slugKey   = \Illuminate\Support\Str::slug($key);
                      $slugLabel = $label !== null ? \Illuminate\Support\Str::slug($label) : null;

                      foreach ([$key, $slugKey] as $try) {
                          if (array_key_exists($try, $group)) return $group[$try];
                      }
                      if ($slugLabel && array_key_exists($slugLabel, $group)) return $group[$slugLabel];

                      foreach ([$key, $slugKey] as $try) {
                          if (array_key_exists($try, $assoc)) return $assoc[$try];
                      }
                      if ($slugLabel && array_key_exists($slugLabel, $assoc)) return $assoc[$slugLabel];

                      if ($label !== null && array_key_exists((string)$label, $group)) return $group[(string)$label];
                      if ($label !== null && array_key_exists((string)$label, $assoc))  return $assoc[(string)$label];

                      return null;
                  };

                  if (!empty($fields)) {
                      foreach ($fields as $f) {
                          $label = $f['label'] ?? ($f['key'] ?? null);
                          $key   = $f['key']   ?? ($label ? \Illuminate\Support\Str::slug((string) $label) : null);
                          if (!$label || !$key) continue;
                          $val = $getFromGroup($group, (string) $key, (string) $label);
                          $rosRows[] = [ (string) $__displayLabel($label, $key), $rosHuman($val) ];
                      }
                  } else {
                      // no schema — dump keys in alpha order
                      ksort($group);
                      foreach ($group as $k => $v) {
                          $rosRows[] = [ (string) $__displayLabel(null, (string)$k), $rosHuman($v) ];
                      }
                  }
              };

              foreach ($byIndex as $idx => $groupVals) {
                  $renderGroup($groupVals, $schemaFields);
              }
          }

          // Keep all rows except ones with an empty label. Do not drop labels just because they contain hyphens.
          $rosRows = array_values(array_filter($rosRows, function ($r) {
              $lab = isset($r[0]) ? trim((string) $r[0]) : '';
              return $lab !== '';
          }));

          // 3) Ultra-defensive fallback: if still empty but decoded has data, dump flat map
          if (empty($rosRows) && !empty($decoded)) {
              $flat = (array) data_get($decoded, 'data', []);
              if (empty($flat)) $flat = (array) data_get($decoded, 'answers', []);
              if (empty($flat)) $flat = (array) $decoded;
              if (!empty($flat)) {
                  foreach ($flat as $k => $v) {
                      if (!is_string($k)) continue;
                      if (str_starts_with($k, '_')) continue;
                      $rosRows[] = [ (string) $__displayLabel(null, (string)$k), $rosHuman($v) ];
                  }
              }
          }
      } catch (\Throwable $e) {
          // As a last resort, if we captured decoded data, try to render it raw
          if (!empty($decoded)) {
              $fallbackMap = (array) ($decoded['data'] ?? $decoded['answers'] ?? $decoded);
              foreach ($fallbackMap as $k => $v) {
                  if (!is_string($k)) continue;
                  if (str_starts_with($k, '_')) continue;
                  $rosRows[] = [ (string) $__displayLabel(null, (string)$k), $rosHuman($v) ];
              }
          } else {
              $rosRows = [];
          }
      }
    @endphp

    @php
      // Hide rows with empty values or placeholders like '-' or '—'
      $visibleRows = array_values(array_filter($rosRows, function ($r) {
          $val = trim((string)($r[1] ?? ''));
          return $val !== '' && $val !== '-' && $val !== '—';
      }));
    @endphp

    @if(empty($visibleRows))
      <p>No clinical notes were found for this consultation.</p>
    @else
      <table class="items" style="margin-top:6px;">
        <tbody>
          @foreach($visibleRows as $row)
            @php
              $label = $row[0] ?? '';
              $disp  = $row[1] ?? '';
            @endphp
            <tr>
              <td style="width:38%">{{ $label }}</td>
              <td>{{ $disp }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    @php
      // keep downstream usage happy
      $first = $first ?? ($items[0] ?? []);
    @endphp

  </div>
</div>

  <div class="panel" style="margin-top:16px;">
    <div class="section-title">Pharmacist Declaration</div>
    <p>
      I confirm that the above named patient has been clinically assessed and supplied medication in accordance with the service protocol.
      The supply is appropriate, counselling has been provided, and relevant records have been completed.
    </p>
    <table class="kv" style="margin-top:8px;">
      <tr>
        <td>Pharmacist Name:</td>
        <td>{{ $declName ?: ($pharmacist['name'] ?? '____________________________') }}</td>
      </tr>
      <tr>
        <td>GPhC Number:</td>
        <td>{{ ($declGphc !== null && $declGphc !== '') ? $declGphc : (data_get($pharmacist ?? [], 'gphc') ?? data_get($pharmacist ?? [], 'gphc_number') ?? '________________') }}</td>
      </tr>
      <tr>
        <td>Date:</td>
        <td>{{ $dateProvided ?: now()->format('d/m/Y') }}</td>
      </tr>
      <tr>
        <td>Signature:</td>
        <td>
          @php $sig = $declSig ?: ($pharmacist['signature'] ?? null); @endphp
          @if(!empty($sig) && is_string($sig))
              @if(str_starts_with($sig, 'data:image'))
                  <img src="{{ $sig }}" alt="Signature" style="height:40px;">
              @else
                  <img src="data:image/png;base64,{{ $sig }}" alt="Signature" style="height:40px;">
              @endif
          @else
              ____________________________
          @endif
        </td>
      </tr>
    </table>
  </div>

</body>
</html>