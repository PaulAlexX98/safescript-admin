<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Invoice</title>
  <style>
    @page { margin: 22mm 18mm; }
    body { font-family: 'Times New Roman', Times, serif; color:#000; font-size:12px; }

    /* Header */
    .header {
      display:flex;
      align-items:center;            /* align with logo baseline */
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
    .total-row td { font-weight:bold; }
  </style>
</head>
<body>

  <div class="header">
    @if(isset($pharmacy['logo']) && is_file($pharmacy['logo']))
      <img src="{{ $pharmacy['logo'] }}" alt="Pharmacy Express">
    @endif
    <div>
      <div class="title">Invoice</div>
      <div class="meta">
        Invoice No: #INV-{{ $ref }}
        &nbsp; | &nbsp; VAT No: {{ $pharmacy['vat'] }}
        &nbsp; | &nbsp; Date: {{ now()->format('d/m/Y') }}
      </div>
    </div>
  </div>

  <div>
    <table class="two-col">
      <tr>
        <td>
          <div class="panel">
            <div class="section-title">From</div>
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
            <div class="section-title">Bill To</div>
            <table class="kv">
              <tr><td>Patient:</td><td>{{ $patient['name'] ?: '—' }}</td></tr>
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
    <div class="section-title">Invoice Details</div>

    <table class="items">
      <thead>
        <tr>
          <th>Description</th>
          <th>Qty</th>
          <th class="right">Unit Price</th>
          <th class="right">Net</th>
        </tr>
      </thead>
      <tbody>
        @php $total = 0; @endphp
        @foreach($items as $line)
          @php
            $qty  = (int)($line['qty'] ?? $line['quantity'] ?? 1);
            $name = $line['name'] ?? $line['title'] ?? 'Service';
            $variation = $line['variation'] ?? $line['strength'] ?? $line['dose'] ?? '';

            // support both minor- and major-unit prices
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
          <td colspan="3" class="right">Total incl. VAT</td>
          <td class="right">£{{ number_format($total, 2) }}</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="panel" style="margin-top:12px;">
    <div class="section-title">Payment Information</div>
    <div>Status: PAID &nbsp; | &nbsp; Date: {{ now()->format('d/m/Y') }}</div>
  </div>

</body>
</html>