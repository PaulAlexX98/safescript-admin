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

</body>
</html>