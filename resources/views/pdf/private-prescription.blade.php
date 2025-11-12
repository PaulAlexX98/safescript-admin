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

  <div class="header">
    @if(isset($pharmacy['logo']) && is_file($pharmacy['logo']))
      <img src="{{ $pharmacy['logo'] }}" alt="Pharmacy Express">
    @endif
    <div>
      <div class="title">Private Prescription</div>
      <div class="meta">Reference: {{ $ref }} | Date: {{ now()->format('d/m/Y') }}</div>
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

  <div class="panel" style="margin-top:16px;">
    <div class="section-title">Medicine prescribed</div>
    @php
      $first = $items[0] ?? [];
      $extraNotes = data_get($meta ?? [], 'clinical_notes')
        ?? data_get($meta ?? [], 'notes')
        ?? data_get($meta ?? [], 'ros.notes');
    @endphp

    <table class="items" style="margin-top:10px;">
      <tbody>
        <tr><th>Date Provided</th><td>{{ now()->format('d/m/Y') }}</td></tr>
        <tr><th>Item</th><td>{{ $first['name'] ?? '—' }}</td></tr>
        <tr><th>Item Strength</th><td>{{ $first['variation'] ?? $first['strength'] ?? '—' }}</td></tr>
        <tr><th>Quantity</th><td>{{ $first['qty'] ?? 1 }}</td></tr>
      </tbody>
    </table>
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
        <td>{{ $pharmacist['name'] ?? '____________________________' }}</td>
      </tr>
      <tr>
        <td>GPhC Number:</td>
        <td>{{ $pharmacist['gphc'] ?? '________________' }}</td>
      </tr>
      <tr>
        <td>Date:</td>
        <td>{{ now()->format('d/m/Y') }}</td>
      </tr>
      <tr>
        <td>Signature:</td>
        <td>
          @php
            $sig = $pharmacist['signature'] ?? null;
          @endphp
          @if(is_string($sig) && $sig !== '')
            @php
              $isDataUri = stripos($sig, 'data:image') === 0;
              $src = $isDataUri ? $sig : ('data:image/png;base64,' . $sig);
            @endphp
            <img src="{{ $src }}" alt="Signature" style="height:40px; max-width:240px; width:auto; display:block;">
          @else
            ____________________________
          @endif
        </td>
      </tr>
    </table>
  </div>

</body>
</html>