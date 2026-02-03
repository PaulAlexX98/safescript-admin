@php($rows = $getState()['rows'] ?? [])
@php($showWeight = collect($rows)->contains(function ($r) {
    $slug = strtolower((string)($r['service_slug'] ?? $r['service'] ?? $r['service_name'] ?? ''));
    $hasWeight = isset($r['weight']) && trim((string)$r['weight']) !== '' && trim((string)$r['weight']) !== '—';
    return $hasWeight || $slug === 'weight-management' || str_contains($slug, 'weight management') || str_contains($slug, 'weight-management');
}))
@php($colspan = $showWeight ? 6 : 5)
<style>
/* Scoped styles for Order History, no Tailwind dependency */
.oh-wrap{margin-top:.25rem;overflow-x:auto}
.oh-sep{height:1px;background:#2f3033;margin:4px 0 10px 0}
.oh-table{width:100%;border-collapse:separate;border-spacing:0 8px;font-size:13px;line-height:1.35}
.oh-table th,.oh-table td{text-align:left;padding:.55rem .75rem;color:#e5e7eb}
.oh-table thead th{color:#9ca3af;font-weight:600;border-bottom:2px solid #495057}
.oh-table tbody tr td{background:#111418;border-top:1px solid #1f2937;border-bottom:1px solid #1f2937;vertical-align:top}
.oh-table tbody tr td:first-child{border-left:1px solid #1f2937;border-top-left-radius:.5rem;border-bottom-left-radius:.5rem}
.oh-table tbody tr td:last-child{border-right:1px solid #1f2937;border-top-right-radius:.5rem;border-bottom-right-radius:.5rem}
.oh-ref{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;white-space:nowrap}
.oh-created{white-space:nowrap}
.oh-items{white-space:normal}
.oh-weight{white-space:nowrap}
.oh-total{white-space:nowrap}
.oh-actions{white-space:nowrap}
.oh-btn{display:inline-flex;align-items:center;justify-content:center;padding:.25rem .6rem;border-radius:.4rem;background:#dc2626;color:#fff;text-decoration:none;font-weight:600;font-size:12px;border:1px solid #b91c1c}
.oh-btn:hover{filter:brightness(1.08)}
.oh-btn:focus{outline:2px solid #b91c1c;outline-offset:2px}
.oh-more{color:#9ca3af}
</style>

<div class="oh-wrap">
  <div class="oh-sep"></div>
  <table class="oh-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Created</th>
        <th>Items</th>
        @if($showWeight)
          <th>Weight</th>
        @endif
        <th>Total</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      @forelse($rows as $row)
        <tr>
          <td class="oh-ref">{{ $row['ref'] }}</td>
          <td class="oh-created">{{ $row['created'] }}</td>
          <td class="oh-items">{!! $row['items'] !!}</td>
          @if($showWeight)
            <td class="oh-weight">{{ $row['weight'] ?? '—' }}</td>
          @endif
          <td class="oh-total">{{ $row['total'] }}</td>
          <td class="oh-actions">
            <a href="{{ $row['url'] }}" target="_blank" rel="noopener" class="oh-btn">View</a>
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="{{ $colspan }}" style="padding:.75rem;color:#9ca3af;">No previous orders</td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>