<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Record of Supply</title>
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
              ?? data_get($meta ?? [], 'consultation_id');
          $sess = $sid ? \App\Models\ConsultationSession::find($sid) : null;

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
                  $data = is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []);
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
      <div class="title">Record of Supply</div>
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
      use Illuminate\Support\Arr;
      use Illuminate\Support\Str;

      // Locate the ConsultationSession if present
      $sid = data_get($meta ?? [], 'consultation_session_id')
          ?? data_get($meta ?? [], 'session_id')
          ?? data_get($meta ?? [], 'consultation_id');

      $sess = null;
      try { if ($sid) { $sess = \App\Models\ConsultationSession::find($sid); } } catch (\Throwable $e) {}

      // Helper for service/treatment slugs
      $slugify = function ($v) { return $v ? Str::slug((string) $v) : null; };
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
              $tpl = Arr::get($sess->templates, 'declaration')
                  ?? Arr::get($sess->templates, 'pharmacist_declaration')
                  ?? Arr::get($sess->templates, 'pharmacist-declaration');
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

              $data = $resp?->data ?? [];
              $data = is_array($data) ? $data : (json_decode($data ?? '[]', true) ?: []);

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
              $tpl = Arr::get($sess->templates, 'clinical_notes')
                  ?? Arr::get($sess->templates, 'record_of_supply')
                  ?? Arr::get($sess->templates, 'supply')
                  ?? Arr::get($sess->templates, 'clinicalNotes');
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

              $data = $resp?->data ?? [];
              $data = is_array($data) ? $data : (json_decode($data ?? '[]', true) ?: []);
              $extraNotes = (string) ($data['other_clinical_notes'] ?? '');

              // pull site and route from the same saved data
              if ($adminSite === '') {
                  $adminSite = $__pickFrom($data, [
                      'administration_site','admin_site','site','injection_site','vaccination_site',
                  ]);
              }
              if ($adminRoute === '') {
                  $adminRoute = $__pickFrom($data, [
                      'administration_route','admin_route','route','vaccination_route','injection_route',
                  ]);
              }
              if ($batchNumber === '') {
                  $batchNumber = $__pickFrom($data, [
                      'batch_number','batch','batch_no','batchNo','lot','lot_number','lot_no','lotNo',
                  ]);
              }
              if ($expiryDate === '') {
                  $expiryDate = $__fmtDate($__pickFrom($data, [
                      'expiry_date','expiry','exp_date','exp','expiryDate','expiry_date_input',
                  ]));
              }
              // resolve supplied date / date provided from the same saved data
              if ($dateProvided === '') {
                  $dateProvided = $__fmtDate($__pickFrom($data, [
                      'dateProvided','date provided','date_provided','date-of-provided','date-of-supply','date_of_supply','supply_date','dispense_date','issue_date','administration_date','admin_date','vaccination_date','date'
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
              $smeta = is_array($sess->meta) ? $sess->meta : (json_decode($sess->meta ?? '[]', true) ?: []);
              $val = data_get($smeta, 'other_clinical_notes')
                  ?? data_get($smeta, 'clinical_notes')
                  ?? data_get($smeta, 'ros.other_clinical_notes');
              if (is_string($val) && trim($val) !== '') $extraNotes = (string) $val;
              if ($adminSite === '') {
                  $v = data_get($smeta, 'administration_site') ?? data_get($smeta, 'admin_site') ?? data_get($smeta, 'site');
                  if (is_string($v) && trim($v) !== '') { $adminSite = trim($v); }
              }
              if ($adminRoute === '') {
                  $v = data_get($smeta, 'administration_route') ?? data_get($smeta, 'admin_route') ?? data_get($smeta, 'route');
                  if (is_string($v) && trim($v) !== '') { $adminRoute = trim($v); }
              }
              if ($batchNumber === '') {
                  $v = data_get($smeta, 'batch_number') ?? data_get($smeta, 'batch') ?? data_get($smeta, 'lot');
                  if (is_string($v) && trim($v) !== '') { $batchNumber = trim($v); }
              }
              if ($expiryDate === '') {
                  $v = data_get($smeta, 'expiry_date') ?? data_get($smeta, 'expiry') ?? data_get($smeta, 'exp_date');
                  if ($v !== null && $v !== '') { $expiryDate = $__fmtDate($v); }
              }
              if ($dateProvided === '') {
                  $v = data_get($smeta, 'date_provided')
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
                  $d = is_array($r->data) ? $r->data : (json_decode($r->data ?? '[]', true) ?: []);
                  $val = data_get($d, 'other_clinical_notes');
                  if (is_string($val) && trim($val) !== '') { $extraNotes = (string) $val; break; }
                  if ($adminSite === '') {
                      $vv = data_get($d, 'administration_site') ?? data_get($d, 'admin_site') ?? data_get($d, 'site');
                      $vv = $__prettyAns($vv);
                      if ($vv !== '') { $adminSite = $vv; }
                  }
                  if ($adminRoute === '') {
                      $vv = data_get($d, 'administration_route') ?? data_get($d, 'admin_route') ?? data_get($d, 'route');
                      $vv = $__prettyAns($vv);
                      if ($vv !== '') { $adminRoute = $vv; }
                  }
                  if ($batchNumber === '') {
                      $vv = $__pickFrom($d, ['batch_number','batch','batch_no','batchNo','lot','lot_number','lot_no','lotNo']);
                      if ($vv !== '') { $batchNumber = $vv; }
                  }
                  if ($expiryDate === '') {
                      $vv = $__pickFrom($d, ['expiry_date','expiry','exp_date','exp','expiryDate','expiry_date_input']);
                      $vv = $__fmtDate($vv);
                      if ($vv !== '') { $expiryDate = $vv; }
                  }
                  if ($dateProvided === '') {
                      $vv = $__pickFrom($d, [
                          'date_provided','date-of-supply','date_of_supply','supply_date',
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
              ?? data_get($meta ?? [], 'clinical_notes')
              ?? data_get($meta ?? [], 'notes')
              ?? data_get($meta ?? [], 'ros.notes')
              ?? '';
      }
      if ($adminSite === '') {
          $adminSite = (string) (data_get($meta ?? [], 'administration_site') ?? data_get($meta ?? [], 'admin_site') ?? '');
      }
      if ($adminRoute === '') {
          $adminRoute = (string) (data_get($meta ?? [], 'administration_route') ?? data_get($meta ?? [], 'admin_route') ?? '');
      }
      if ($batchNumber === '') {
          $batchNumber = (string) (data_get($meta ?? [], 'batch_number') ?? data_get($meta ?? [], 'batch') ?? data_get($meta ?? [], 'lot') ?? '');
      }
      if ($expiryDate === '') {
          $expiryDate = $__fmtDate(data_get($meta ?? [], 'expiry_date') ?? data_get($meta ?? [], 'expiry') ?? data_get($meta ?? [], 'exp_date'));
      }
      if ($dateProvided === '') {
          $dateProvided = $__fmtDate(
              data_get($meta ?? [], 'date_provided')
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

  <div class="panel" style="margin-top:16px;">
    <div class="section-title">Clinical Notes</div>

    {{-- ==== Dynamic Record of Supply form rendering (schema + answers) ==== --}}
    @php
      // ==== Dynamic Record of Supply form rendering (schema + answers) ====
      $toArray = function ($v) {
          if (is_array($v)) return $v;
          if (is_string($v)) { $d = json_decode($v, true); return is_array($d) ? $d : []; }
          if ($v instanceof \Illuminate\Contracts\Support\Arrayable) return $v->toArray();
          return [];
      };

      $slugify = function ($v) { return \Illuminate\Support\Str::slug((string) $v); };

      // Resolve the Clinical Notes / Record of Supply form for this consultation
      $rosSections = [];
      $rosAnswers  = [];

      try {
          // Prefer the response we already loaded above ($resp)
          if (isset($resp) && $resp) {
              $raw = $resp->data ?? [];
              $rosAnswers = is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []);
          }

          // Decode the matched form schema from $rosForm (if available from above fallback chain)
          if (!isset($rosForm) || !$rosForm) {
              if (!empty($rosFormId)) {
                  $rosForm = \App\Models\ClinicForm::find($rosFormId);
              }
          }

          if (isset($rosForm) && $rosForm) {
              $rawSchema = is_array($rosForm->schema) ? $rosForm->schema : (json_decode($rosForm->schema ?? '[]', true) ?: []);

              // Normalise into sections [ { title, fields: [...] } ]
              if (is_array($rawSchema) && !empty($rawSchema)) {
                  if (array_key_exists('fields', $rawSchema[0] ?? [])) {
                      $rosSections = $rawSchema;
                  } else {
                      $current = ['title' => null, 'fields' => []];
                      foreach ($rawSchema as $blk) {
                          $type = $blk['type'] ?? null;
                          $data = (array) ($blk['data'] ?? []);
                          if ($type === 'section') {
                              if (!empty($current['fields'])) $rosSections[] = $current;
                              $current = ['title' => $data['label'] ?? ($data['title'] ?? null), 'fields' => []];
                          } else {
                              $field = ['type' => $type];
                              foreach (['label','key','required','options','content','accept','multiple'] as $k) {
                                  if (array_key_exists($k, $data)) $field[$k] = $data[$k];
                              }
                              $current['fields'][] = $field;
                          }
                      }
                      if (!empty($current['fields'])) $rosSections[] = $current;
                  }
              }
          }

          // Prefer flattened top-level maps (data, answers)
          if (is_array($rosAnswers)) {
              $rosAnswers = (array) data_get($rosAnswers, 'data', [])
                         + (array) data_get($rosAnswers, 'answers', [])
                         + (array) $rosAnswers; // fall back to raw
          } else {
              $rosAnswers = [];
          }

          // If answer list is row-shaped, convert to map
          if (!empty($rosAnswers) && array_keys($rosAnswers) === range(0, count($rosAnswers) - 1)) {
              $map = [];
              foreach ($rosAnswers as $row) {
                  if (!is_array($row)) continue;
                  $k = $row['key'] ?? ($row['question'] ?? ($row['label'] ?? null));
                  $v = $row['value'] ?? ($row['answer'] ?? ($row['selected'] ?? ($row['raw'] ?? null)));
                  if ($k !== null) $map[(string) $k] = $v;
              }
              if (!empty($map)) $rosAnswers = $map;
          }

          // Build q_N walk and ordering from schema
          $rosOrder = [];
          $idx = 0;
          $inputTypes = ['text_input','text','select','textarea','date','radio','checkbox','file','file_upload','image','email','number','tel','yesno','signature'];
          foreach ($rosSections as $sec) {
              foreach (($sec['fields'] ?? []) as $f) {
                  $t = $f['type'] ?? '';
                  if (!in_array($t, $inputTypes, true)) continue;
                  $key = $f['key'] ?? ($f['label'] ? $slugify($f['label']) : null);
                  if ($key) {
                      $rosOrder[$key] = $idx;
                      $rosOrder[$slugify($key)] = $idx;
                  }
                  if (!empty($f['label'])) {
                      $rosOrder[$slugify($f['label'])] = $idx;
                  }
                  $idx++;
              }
          }

          // Helper mappers
          $human = function ($v) {
              if (is_bool($v)) return $v ? 'Yes' : 'No';
              if ($v === 1 || $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') return 'Yes';
              if ($v === 0 || $v === '0' || $v === 'false' || $v === 'no'  || $v === 'off') return 'No';
              if (is_array($v)) {
                  $parts = [];
                  $list = \Illuminate\Support\Arr::isAssoc($v) ? [$v] : $v;
                  foreach ($list as $x) {
                      if (is_scalar($x)) $parts[] = (string) $x;
                      elseif (is_array($x)) $parts[] = $x['label'] ?? $x['value'] ?? $x['text'] ?? $x['name'] ?? null;
                  }
                  $parts = array_values(array_filter($parts, fn($s) => is_string($s) ? trim($s) !== '' : (bool)$s));
                  return implode(', ', $parts);
              }
              if ($v === null) return '';
              return trim((string) $v);
          };

          $labelise = function ($k) {
              $k = str_replace(['_', '-'], ' ', (string) $k);
              $k = preg_replace('/\s+/', ' ', trim($k));
              return ucwords($k);
          };

          // Reorder answers by schema order for nicer output
          if (!empty($rosAnswers)) {
              uksort($rosAnswers, function($a,$b) use ($rosOrder,$slugify){
                  $ia = $rosOrder[$a] ?? $rosOrder[$slugify($a)] ?? PHP_INT_MAX - 1;
                  $ib = $rosOrder[$b] ?? $rosOrder[$slugify($b)] ?? PHP_INT_MAX - 1;
                  return $ia <=> $ib;
              });
          }

          // Build grouped rows by sections and collect any text_block content to show above the table
          $rosGrouped = [];
          $rosContent = [];    // $__secTitle => list of html/paragraph blocks
          $rosSectionOrder = [];

          foreach ($rosSections as $sec) {
              $title = $sec['title'] ?? 'Record of Supply';
              if (!isset($rosGrouped[$title])) {
                  $rosGrouped[$title] = [];
                  $rosContent[$title] = [];
                  $rosSectionOrder[] = $title;
              }

              $i = 0;
              foreach (($sec['fields'] ?? []) as $f) {
                  $t = $f['type'] ?? '';
                  // Render static text blocks above the table
                  if (in_array($t, ['text_block','rich_text'], true)) {
                      $html  = (string) ($f['content'] ?? '');
                      if (trim(strip_tags($html)) !== '') {
                          // If it's not html, convert newlines to paragraphs
                          $hasHtml = (bool) preg_match('/<\w+[^>]*>/', $html ?? '');
                          if ($hasHtml) {
                              $rosContent[$title][] = $html;
                          } else {
                              $lines = preg_split("/\r\n|\r|\n/", $html);
                              $clean = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
                              $para  = '';
                              foreach ($clean as $ln) {
                                  $para .= '<p>'.e($ln).'</p>';
                              }
                              $rosContent[$title][] = $para;
                          }
                      }
                      continue;
                  }

                  // Only include input-like fields in the Q A table
                  if (!in_array($t, $inputTypes, true)) continue;

                  $lab = $f['label'] ?? ($f['key'] ?? 'Question');
                  $key = $f['key'] ?? ($lab ? $slugify($lab) : null);

                  // Pick an answer across common shapes  try exact key, slugged key, and q_i fallback
                  $ans = $rosAnswers[$key] ?? $rosAnswers[$slugify($key)] ?? $rosAnswers['q_'.$i] ?? null;

                  if (is_array($ans) && \Illuminate\Support\Arr::isAssoc($ans)) {
                      $ans = $ans['raw'] ?? $ans['answer'] ?? $ans['value'] ?? $ans['label'] ?? $ans['text'] ?? $ans;
                  }

                  $disp = $human($ans);

                  // If this looks like a date field, format as dd/mm/YYYY
                  $labProbe = is_string($lab) ? strtolower(trim($lab)) : '';
                  $keyProbe = is_string($key) ? strtolower(trim($key)) : '';
                  if ($disp !== 'No response provided' && $disp !== '' && (
                        preg_match('~\bdate\b~', $labProbe)
                     || preg_match('~\bdate\b~', $keyProbe)
                     || preg_match('~(supply|provided|administration|vaccination|dispense|issue).*date~', $labProbe)
                     || preg_match('~(supply|provided|administration|vaccination|dispense|issue).*date~', $keyProbe)
                  )) {
                      $disp = $__fmtDate($disp);
                  }

                  if ($disp === '' || $disp === null) $disp = 'No response provided';

                  $rosGrouped[$title][] = [$lab, $disp];
                  $i++;
              }
          }

      } catch (\Throwable $e) { /* ignore */ }
    @endphp

    @if(!empty($rosSections))
      @foreach($rosSectionOrder as $__secTitle)
        @php $__contentBlocks = $rosContent[$__secTitle] ?? []; @endphp
        @if(!empty($__contentBlocks))
          <div class="panel" style="margin:8px 0 6px 0;">
            @foreach($__contentBlocks as $__html)
              {!! $__html !!}
            @endforeach
          </div>
        @endif
        <table class="items" style="margin-top:6px;">
          <tbody>
            @foreach(($rosGrouped[$__secTitle] ?? []) as $__row)
              <tr>
                <td style="width:55%">{{ $__row[0] }}</td>
                <td>{{ $__row[1] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endforeach
    @endif

    @php
      // ensure we have the first line item
      $first = $first ?? ($items[0] ?? []);
    @endphp

  </div>

</body>
</html>