<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Notification of Treatment Issued</title>
  <style>
    @page { margin: 22mm 18mm; }
    html, body { height: 100%; }
    body { font-family: 'Times New Roman', Times, serif; color:#000; font-size:12px; position: relative; }

    .header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; }
    .pharmacy-head { line-height:1.35; }
    .pharmacy-head .name { font-size:14px; font-weight:bold; }
    .date { font-size:12px; }

    .doc-title { font-size:20px; font-weight:bold; margin:8px 0 18px; }

    .patient { margin-bottom:14px; }
    .patient .row { line-height:1.5; } /* no bold anywhere here */

    p { margin:0 0 10px; }
    ul { margin:6px 0 10px 18px; }

    .sig-block { margin-top:22px; }
    .sig-line { margin-top:24px; margin-bottom:4px; width:240px; border-top:1px solid #000; }
    .sig-name { margin-top:2px; }

    .footer {
      position:fixed;
      left:0; right:0; bottom:14mm;
      padding-top:6px;
      border-top:1px solid #bbb;
      font-size:10px;
    }
    .footer .line { line-height:1.45; }
  </style>
</head>
<body>
@php
  $fmt = function ($s) { try { return \Carbon\Carbon::parse($s)->format('d/m/Y'); } catch (\Throwable $e) { return (string)$s; } };

  $ph = $pharmacy ?? [];
  $phName  = $ph['name'] ?? 'Pharmacy Express';
  $phAddr  = $ph['address'] ?? '';
  $phTel   = $ph['tel'] ?? ($ph['phone'] ?? '');
  $phEmail = $ph['email'] ?? '';

  $p = $patient ?? [];
  $pName  = trim((string)($p['name'] ?? ''));
  $pDob   = !empty($p['dob']) ? $fmt($p['dob']) : null;
  $pAddr  = trim((string)($p['address'] ?? ''));
  $pEmail = trim((string)($p['email'] ?? ''));
  $pPhone = trim((string)($p['phone'] ?? ''));

  // one-line item from order
  $itemLine = '';
  try {
    $itemsArr = is_array($items ?? null) ? $items : (is_array($order->items ?? null) ? $order->items : []);
    $first = $itemsArr[0] ?? [];
    if ($first) {
      $qty  = $first['quantity'] ?? $first['qty'] ?? 1;
      $name = $first['name'] ?? $first['title'] ?? $first['product'] ?? '';
      $dose = $first['dose'] ?? $first['dosage'] ?? $first['strength'] ?? '';
      $parts = [];
      if ($qty)  $parts[] = $qty.' x';
      if ($name) $parts[] = $name;
      if ($dose) $parts[] = $dose;
      $itemLine = trim(implode(' ', $parts));
    }
  } catch (\Throwable $e) {}
  if ($itemLine === '') $itemLine = '1 x [medicine]';

  // pharmacist details consistent with private-prescription
  $declName = $declName
    ?? data_get($pharmacist ?? [], 'name')
    ?? data_get($meta ?? [], 'pharmacist.name')
    ?? '';

  $declGphc = $declGphc
    ?? data_get($pharmacist ?? [], 'gphc')
    ?? data_get($pharmacist ?? [], 'gphc_number')
    ?? data_get($meta ?? [], 'pharmacist.gphc')
    ?? data_get($meta ?? [], 'pharmacist.gphc_number')
    ?? '';

  // header date prefers supplied date
  $dateProvided = $dateProvided
    ?? data_get($meta ?? [], 'date_provided')
    ?? data_get($meta ?? [], 'date')
    ?? now()->toDateString();
  $topDate = $fmt($dateProvided);
@endphp

<div class="header">
  <div class="pharmacy-head">
    <div class="name">{{ $phName }}</div>
    @if($phAddr)<div>{{ $phAddr }}</div>@endif
    @if($phEmail || $phTel)
      <div>
        @if($phEmail) {{ $phEmail }} @endif
        @if($phEmail && $phTel) | @endif
        @if($phTel) {{ $phTel }} @endif
      </div>
    @endif
  </div>
  <div class="date">Date {{ $topDate }}</div>
</div>

<div class="doc-title">Notification of Treatment Issued</div>

<div class="patient">
  <div class="row">{{ $pName }}@if($pDob), {{ $pDob }}@endif</div>
  @if($pAddr)<div class="row">{{ $pAddr }}</div>@endif
  @if($pEmail || $pPhone)
    <div class="row">
      @if($pEmail) {{ $pEmail }} @endif
      @if($pEmail && $pPhone) | @endif
      @if($pPhone) {{ $pPhone }} @endif
    </div>
  @endif
</div>

<p>Dear Doctor or to whom it may concern</p>

<p>
  This patient received an assessment through our service for weight management. Following clinical review the following medication was supplied
</p>
<ul>
  <li>{{ $itemLine }}</li>
</ul>

<p>
  We follow national guidance and safe prescribing checks including medical history medicines allergies BMI and relevant clinical criteria.
  Patients are advised about correct use of medication potential side effects and when to seek urgent medical advice.
  Health promotion advice is also provided regarding diet activity and regular review.
</p>

<p>
  If you have any concerns regarding this patient’s suitability for this treatment please contact us and we will review immediately.
</p>

<div class="sig-block">
  <div class="sig-line"></div>
  <div class="sig-name">
    {{ $declName !== '' ? $declName : 'Prescribing clinician' }}<br>
    @if($declGphc) GMC {{ $declGphc }} @endif
  </div>
</div>

<div class="footer">
  <div class="line">{{ $phName }}</div>
  @if($phAddr)<div class="line">{{ $phAddr }}</div>@endif
  @if($phEmail || $phTel)
    <div class="line">
      @if($phEmail) {{ $phEmail }} @endif
      @if($phEmail && $phTel) | @endif
      @if($phTel) {{ $phTel }} @endif
    </div>
  @endif
</div>

</body>
</html>