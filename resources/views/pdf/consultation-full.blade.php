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
    @if(is_file($pharmacy['logo']))
      <img src="{{ $pharmacy['logo'] }}" alt="Pharmacy Express">
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
            $variation = $line['variation'] ?? $line['strength'] ?? $line['dose'] ?? '';
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
          return [];
      };

      $rafAnswers = [];

      // 1) Prefer answers from $meta if present
      $metaArr = $toArray($meta ?? []);
      $rafAnswers = data_get($metaArr, 'assessment.answers', []);
      if (empty($rafAnswers)) {
          $rafAnswers = $metaArr['answers'] ?? [];
      }

      // 2) Try pulling from latest ConsultationFormResponse (RAF) using a session id from meta
      $sid = $metaArr['consultation_session_id']
          ?? $metaArr['session_id']
          ?? $metaArr['consultation_id']
          ?? null;

      if (empty($rafAnswers) && $sid) {
          try {
              /** @var \App\Models\ConsultationFormResponse|null $raf */
              $raf = \App\Models\ConsultationFormResponse::query()
                  ->where('consultation_session_id', $sid)
                  ->where(function($q){
                      $q->where('form_type', 'raf')
                        ->orWhere('title', 'like', '%raf%')
                        ->orWhere('form_type', 'like', '%risk%')
                        ->orWhere('title', 'like', '%risk%');
                  })
                  ->latest('id')
                  ->first();

              if ($raf) {
                  $raw = $raf->data;
                  $decoded = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
                  if (is_array($decoded)) {
                      $rafAnswers =
                          (is_array($decoded['data'] ?? null) ? $decoded['data'] : [])
                          + (is_array($decoded['answers'] ?? null) ? $decoded['answers'] : [])
                          + (is_array(data_get($decoded, 'assessment.answers')) ? data_get($decoded, 'assessment.answers') : []);
                      // If still empty, and the decoded is a flat map, use it directly
                      if (empty($rafAnswers)) { $rafAnswers = $decoded; }
                  }
              }
          } catch (\Throwable $e) {}
      }

      // Normalise values for display
      $human = function($v) {
          if (is_bool($v)) return $v ? 'Yes' : 'No';
          if ($v === 1 || $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on') return 'Yes';
          if ($v === 0 || $v === '0' || $v === 'false' || $v === 'no'  || $v === 'off') return 'No';
          if (is_array($v)) return implode(', ', array_map(function($x){ return is_scalar($x) ? (string)$x : json_encode($x); }, $v));
          if ($v === null || $v === '') return '—';
          return (string)$v;
      };

      $labelise = function($k){
          $k = str_replace(['_', '-'], ' ', (string)$k);
          $k = preg_replace('/\s+/', ' ', trim($k));
          $k = ucwords($k);
          return $k;
      };
    @endphp

    <div class="section-title">Clinical Assessment</div>
    @if(!empty($rafAnswers) && is_array($rafAnswers))
      <table class="items">
        <thead>
          <tr>
            <th style="width:55%">Question</th>
            <th>Answer</th>
          </tr>
        </thead>
        <tbody>
          @foreach($rafAnswers as $q => $a)
            @php
              // Skip obviously non-field structures
              if (is_array($a) && isset($a['label']) && isset($a['value'])) {
                  $label = $a['label'];
                  $value = $a['value'];
              } else {
                  $label = $labelise($q);
                  $value = $a;
              }
            @endphp
            <tr>
              <td>{{ $label }}</td>
              <td>{{ $human($value) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      @php
          // Detect and extract any image fields from RAF answers
          $rafImages = [];
          if (is_array($rafAnswers)) {
              foreach ($rafAnswers as $key => $val) {
                  $push = function($k, $v) use (&$rafImages) {
                      if (!is_string($v) || $v === '') return;
                      if (str_starts_with($v, 'data:image/')) { $rafImages[$k] = $v; return; }
                      if (preg_match('~\.(?:jpe?g|png|gif|webp)$~i', $v)) { $rafImages[$k] = $v; return; }
                  };

                  if (is_string($val)) {
                      $push($key, $val);
                  } elseif (is_array($val)) {
                      // Check common nested shapes
                      foreach (['url','data','dataUrl','data_url','path','image','image_url','imagePath'] as $nk) {
                          if (isset($val[$nk]) && is_string($val[$nk])) { $push($key, $val[$nk]); break; }
                      }
                      // Or any string inside the array
                      if (!isset($rafImages[$key])) {
                          foreach ($val as $v) { if (is_string($v)) { $push($key, $v); if (isset($rafImages[$key])) break; } }
                      }
                  }
              }
          }
      // Collapse duplicates: prefer data/URL over Path for the same base label
      if (!empty($rafImages)) {
          $rafBest = [];
          foreach ($rafImages as $k => $v) {
              $base = preg_replace('/\b(url|path)\b/i', '', (string) $k);
              $base = trim(preg_replace('/[_-]+/', ' ', $base));

              $score = 0; // 3=data:, 2=http(s), 1=path
              if (is_string($v)) {
                  if (str_starts_with($v, 'data:image/')) $score = 3;
                  elseif (preg_match('~^https?://~i', $v)) $score = 2;
                  else $score = 1;
              }

              if (!isset($rafBest[$base]) || $score > $rafBest[$base]['score']) {
                  $rafBest[$base] = [
                      'src' => $v,
                      'score' => $score,
                      'label' => $base,
                  ];
              }
          }
          // Rebuild $rafImages with clean labels and chosen src
          $rafImages = [];
          foreach ($rafBest as $base => $info) {
              $rafImages[$base] = $info['src'];
          }
      }
      @endphp

      @if(!empty($rafImages))
        <div style="margin-top:18px;">
          <div class="section-title">Uploaded Images</div>
          <table class="items">
            <tbody>
              @php $__seenLabels = []; @endphp
              @foreach($rafImages as $label => $src)
                  @php
                      $imgSrc = $src;
                      // If not already a data URL, try to convert local/storage paths to base64 data URLs
                      if (!is_string($imgSrc)) $imgSrc = '';

                      $isHttp = preg_match('~^https?://~i', $imgSrc);
                      $isData = str_starts_with($imgSrc, 'data:image/');
                      $looksLikePath = !$isHttp && !$isData;

                      // If it's a remote URL, try to inline as data: URL for DOMPDF (avoids enable_remote requirement)
                      if ($isHttp && !$isData) {
                          try {
                              // Attempt to read the remote image
                              $ctx = stream_context_create([
                                  'http' => ['timeout' => 5],
                                  'https' => ['timeout' => 5]
                              ]);
                              $bin = @file_get_contents($imgSrc, false, $ctx);
                              if ($bin !== false && strlen($bin) > 0) {
                                  // Try to infer mime type from headers first
                                  $mime = 'image/png';
                                  $headers = @get_headers($imgSrc, 1);
                                  if (is_array($headers)) {
                                      foreach ($headers as $hk => $hv) {
                                          if (strtolower($hk) === 'content-type') {
                                              $ct = is_array($hv) ? end($hv) : $hv;
                                              if (is_string($ct) && str_starts_with(strtolower($ct), 'image/')) { $mime = $ct; }
                                              break;
                                          }
                                      }
                                  }
                                  // Fallback: guess from extension
                                  if ($mime === 'image/png') {
                                      if (preg_match('~\.(jpe?g)$~i', $imgSrc)) $mime = 'image/jpeg';
                                      elseif (preg_match('~\.(gif)$~i', $imgSrc)) $mime = 'image/gif';
                                      elseif (preg_match('~\.(webp)$~i', $imgSrc)) $mime = 'image/webp';
                                  }
                                  $imgSrc = 'data:' . $mime . ';base64,' . base64_encode($bin);
                              }
                          } catch (\Throwable $e) { /* ignore and let it fall back */ }
                      }

                      if ($looksLikePath) {
                          try {
                              $path = $imgSrc;
                              // Common cases: values like "public/..", "uploads/...", or "/storage/..."
                              if (str_starts_with($path, '/')) { $path = ltrim($path, '/'); }

                              // If begins with storage/, translate to the underlying disk path
                              if (str_starts_with($path, 'storage/')) {
                                  // default public disk stores files under storage/app/public
                                  $rel = substr($path, strlen('storage/'));
                                  $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'));
                                  if ($disk->exists($rel)) {
                                      $mime = method_exists($disk, 'mimeType') ? ($disk->mimeType($rel) ?: 'image/png') : 'image/png';
                                      $data = $disk->get($rel);
                                      if ($data) { $imgSrc = 'data:' . $mime . ';base64,' . base64_encode($data); }
                                  }
                              } else {
                                  // Try on the configured default disk directly
                                  $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'public'));
                                  if ($disk->exists($path)) {
                                      $mime = method_exists($disk, 'mimeType') ? ($disk->mimeType($path) ?: 'image/png') : 'image/png';
                                      $data = $disk->get($path);
                                      if ($data) { $imgSrc = 'data:' . $mime . ';base64,' . base64_encode($data); }
                                  } else {
                                      // Finally try absolute file path on the server
                                      $abs = base_path($path);
                                      if (!is_file($abs)) { $abs = public_path($path); }
                                      if (is_file($abs)) {
                                          $mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: 'image/png') : 'image/png';
                                          $data = @file_get_contents($abs);
                                          if ($data) { $imgSrc = 'data:' . $mime . ';base64,' . base64_encode($data); }
                                      }
                                  }
                              }
                          } catch (\Throwable $e) {}
                      }
                  @endphp
                  <tr>
                    @php
                        $cleanLabel = preg_replace('/\b(url|path)\b/i', '', $label);
                        $cleanLabel = trim(preg_replace('/[_-]+/', ' ', $cleanLabel));
                        $cleanLabel = ucwords($cleanLabel);
                        // Skip if the image could not be resolved to a data URL (prevents broken/placeholder rows)
                        if (!is_string($imgSrc) || !str_starts_with($imgSrc, 'data:image/')) {
                            // continue to next row
                            $__skipRow = true;
                        } else {
                            $__skipRow = false;
                        }
                        // Deduplicate by cleaned label (only keep the first successfully resolved one)
                        if (!$__skipRow && isset($__seenLabels[$cleanLabel])) {
                            $__skipRow = true;
                        }
                        if (!$__skipRow) { $__seenLabels[$cleanLabel] = true; }
                    @endphp
                    @if($__skipRow)
                        @continue
                    @endif
                    <td style="width:35%; vertical-align:top;">{{ $cleanLabel }}</td>
                    <td>
                      <img src="{{ $imgSrc }}" style="max-width:360px; max-height:240px; border:1px solid #d0d0d0; border-radius:6px; padding:2px;">
                    </td>
                  </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @else
      <div class="muted">No risk assessment answers were found for this consultation.</div>
    @endif
  </div>

</body>
</html>