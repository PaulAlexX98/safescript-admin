@php
  // resolve basics
  $ph = $pharmacy ?? [];
  $today = now()->format('d m Y');

  // fetch session by id in meta
  $sid = data_get($meta ?? [], 'consultation_session_id')
      ?? data_get($meta ?? [], 'session_id')
      ?? data_get($meta ?? [], 'consultation_id');
  $sess = $sid ? \App\Models\ConsultationSession::find($sid) : null;

  // pull order and items
  $order = $order ?? ($sess?->order ?? null);
  $items = is_array($items ?? null) ? $items : (is_array($order?->items ?? null) ? $order->items : []);

  $oMeta = [];
  try { $oMeta = is_array($order?->meta ?? null) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []); } catch (\Throwable $e) {}

  // patient block from Patient model if linked then fall back to meta
  $pat = [
    'name'   => $patient['name'] ?? data_get($order, 'customer_name') ?? trim(((string) data_get($meta,'first_name')).' '.((string) data_get($meta,'last_name'))),
    'dob'    => $patient['dob'] ?? data_get($order, 'customer_dob') ?? data_get($meta, 'dob'),
    'addr'   => $patient['address'] ?? trim(implode(', ', array_filter([
                  data_get($patient ?? [], 'address1') ?: data_get($meta,'address1'),
                  data_get($patient ?? [], 'address2') ?: data_get($meta,'address2'),
                  data_get($patient ?? [], 'city')     ?: data_get($meta,'city'),
                  data_get($patient ?? [], 'postcode') ?: data_get($meta,'postcode'),
                  data_get($patient ?? [], 'country')  ?: data_get($meta,'country') ?: 'United Kingdom'
               ]))),
    'email'  => $patient['email'] ?? data_get($order, 'email') ?? data_get($meta,'email'),
    'phone'  => $patient['phone'] ?? data_get($order, 'phone') ?? data_get($meta,'phone'),
    'nhs'    => data_get($patient ?? [], 'nhs_number') ?? data_get($meta,'nhs_number'),
  ];

  // gp block best effort from RAF answers or meta
  $gpName  = data_get($meta,'gp.name')  ?? data_get($meta,'gp_name');
  $gpEmail = data_get($meta,'gp.email') ?? data_get($meta,'gp_email');
  $gpAddr  = trim(implode(', ', array_filter([
      data_get($meta,'gp.address') ?? null,
      data_get($meta,'gp.address1') ?? null,
      data_get($meta,'gp.address2') ?? null,
      data_get($meta,'gp.city') ?? null,
      data_get($meta,'gp.postcode') ?? null,
  ])));

  // simple list of supplied medicines
  $lines = [];
  $norm = fn($v) => is_array($v) ? $v : (is_string($v) ? (json_decode($v, true) ?: []) : []);
  foreach ($norm($items) as $it) {
      $qty = (int) ($it['qty'] ?? $it['quantity'] ?? 1);
      if ($qty < 1) $qty = 1;
      $name = $it['name'] ?? $it['title'] ?? $it['product_name'] ?? 'Medication';
      $opt  = data_get($it,'variation') ?? data_get($it,'variant') ?? data_get($it,'dose') ?? data_get($it,'strength') ?? data_get($it,'option');
      if (is_array($opt)) $opt = ($opt['label'] ?? $opt['value'] ?? '');
      $lines[] = trim($qty.' x '.$name.' '.($opt ?: ''));
  }

  // brief service name
  $service = data_get($meta,'service') ?? data_get($meta,'serviceName') ?? data_get($meta,'title') ?? 'Service';

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

  // ensure display variables are set for later template usage
  if (empty($phName) && !empty($declName)) { $phName = $declName; }
  if (empty($gphc) && !empty($declGphc))   { $gphc = $declGphc; }

@endphp

<div style="font-size:12px; line-height:1.5; margin-bottom:12px;">
 
  @php $pname = $ph['name'] ?? data_get($pharmacy ?? [], 'name'); @endphp
  <div>{{ $pname ?: 'Pharmacy Express' }}</div>
  @if(!empty($ph['address'])) <div>{{ $ph['address'] }}</div> @endif
  @if(!empty($ph['email']) || !empty($ph['tel']))
    <div>
      @if(!empty($ph['email'])) {{ $ph['email'] }} @endif
      @if(!empty($ph['email']) && !empty($ph['tel'])) | @endif
      @if(!empty($ph['tel'])) {{ $ph['tel'] }} @endif
    </div>
  @endif
  <div>Date {{ ($dateProvided ?? null) ? \Carbon\Carbon::parse($dateProvided)->format('d/m/Y') : now()->format('d/m/Y') }}</div>
</div>

<div style="width:100%; display:flex; justify-content:flex-start; align-items:flex-start; margin-bottom:16px;">
  <div style="font-size:20px; font-weight:bold;">Notification of Treatment Issued</div>
</div>

<div style="font-size:12px; line-height:1.5; margin-bottom:14px;">
  
  <div>{{ $pat['name'] }}@if(!empty($pat['dob'])), {{ \Carbon\Carbon::parse($pat['dob'])->format('d/m/Y') }}@endif</div>
  @if(!empty($pat['addr'])) <div>{{ $pat['addr'] }}</div> @endif
  @if(!empty($pat['email']) || !empty($pat['phone']))
    <div>
      @if(!empty($pat['email'])) {{ $pat['email'] }} @endif
      @if(!empty($pat['email']) && !empty($pat['phone'])) | @endif
      @if(!empty($pat['phone'])) {{ $pat['phone'] }} @endif
    </div>
  @endif
</div>

<p style="font-size:12px; line-height:1.6;">Dear Doctor or To whom it may concern</p>

<p style="font-size:12px; line-height:1.6;">
  This patient received an assessment from our clinical team on the date shown above for weight management and was supplied the treatment ordered through our service. The patient informed us that you are their regular GP so we are sharing this update for your awareness.
</p>

<p style="font-size:12px; line-height:1.6;">
  The treatment was issued after an online consultation confirmed suitability for private prescribing. We reviewed medical history current medicines allergies BMI and previous efforts to reduce weight.
</p>

<p style="font-size:12px; line-height:1.6;">
  We follow strict clinical standards and national guidance to ensure safe and responsible prescribing
</p>
<ul style="font-size:12px; margin-left:18px;">
  <li>We follow the medicine information sheets and all relevant safety criteria</li>
  <li>We request photographic evidence of weight and body shape at the start and at regular intervals</li>
  <li>We initiate treatment only when BMI meets required thresholds including BMI thirty or BMI twenty seven or above with weight related conditions for medicines such as Mysimba Saxenda Wegovy and Mounjaro or BMI twenty eight for Orlistat and BMI twenty seven point five for higher risk ethnic groups following national guidance</li>
  <li>We require GP details for every order and do not prescribe if GP details are withheld</li>
  <li>We review patients at twelve weeks for Orlistat sixteen weeks for Mysimba and seventeen weeks for Saxenda once on the maintenance dose to confirm progress</li>
  <li>We request updated photographic evidence of weight and eligibility every three to six months or more often when clinically needed</li>
</ul>

@if(!empty($lines))
  <p style="font-size:12px; line-height:1.6;">Medication supplied</p>
  <ul style="font-size:12px; margin-left:18px;">
    @foreach($lines as $l)
      <li>{{ $l }}</li>
    @endforeach
  </ul>
@endif

<p style="font-size:12px; line-height:1.6;">
  The patient has been advised on correct use possible side effects lifestyle guidance including diet physical activity and when urgent medical help is needed. They have been invited to contact us with any questions or concerns about their treatment.
</p>

<p style="font-size:12px; line-height:1.6;">
  This treatment is provided privately and you are not expected to assume responsibility for prescribing it. We will continue to assess any further requests from the patient as part of their ongoing care.
</p>

<p style="font-size:12px; line-height:1.6;">
  If you need further information or if you believe the patient should not continue this treatment such as concerns about BMI or a history of eating disorders please contact us and we will review the case immediately.
</p>

<p style="font-size:12px; line-height:1.6;">
  For air travel we kindly request that the patient is permitted to carry this medication in hand luggage to avoid freezing damage. Additional items such as needles or syringes may be placed in hold luggage.
</p>

<p style="font-size:12px; line-height:1.6; margin-top:20px;">Kind regards</p>

<div style="margin-top:14px;">
  @if(!empty($declSig) && is_string($declSig))
    @php
      $sigSrc = null;
      $s = trim($declSig);
      if (\Illuminate\Support\Str::startsWith($s, ['data:image', 'http://', 'https://', '/'])) {
          $sigSrc = $s;
      } else {
          $sigSrc = 'data:image/png;base64,'.$s;
      }
    @endphp
    <img src="{{ $sigSrc }}" alt="Signature" style="height:40px;">
  @endif
</div>
<p style="font-size:12px; line-height:1.6; margin-top:8px;">
  {{ $phName ?: ($pharmacist['name'] ?? '____________________________') }}<br>
  @if(!empty($gphc)) GPHC Number {{ $gphc }} @endif
</p>