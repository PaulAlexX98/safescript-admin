{{-- resources/views/filament/widgets/booking-status-row.blade.php --}}
@php($rows = $rows ?? [])
@php($total = max(1, (int) ($total ?? 0)))

<table class="w-full text-sm">
    <thead>
    <tr class="border-b">
        <th class="py-2 text-left">Status</th>
        <th class="py-2 text-right">Count</th>
        <th class="py-2 text-right">Percentage</th>
        <th class="py-2 text-right">Revenue Impact</th>
    </tr>
    </thead>
    <tbody>
    @foreach($rows as $r)
        @php($label = (string) ($r['label'] ?? ''))
        @php($count = (int) ($r['count'] ?? 0))
        @php($impact = $r['impact'] ?? null)
        @php($pct = min(100, max(0, $total > 0 ? round(($count / $total) * 100, 1) : 0)))

        @php($barClass = 'bg-sky-500')
        @switch(strtolower($label))
            @case('completed')
                @php($barClass = 'bg-emerald-500')
                @break
            @case('rejected')
                @php($barClass = 'bg-rose-500')
                @break
            @case('unpaid')
                @php($barClass = 'bg-amber-500')
                @break
        @endswitch

        <tr class="border-b">
            <td class="py-2">{{ $label }}</td>
            <td class="py-2 text-right">{{ number_format($count) }}</td>
            <td class="py-2 text-right">
                <div class="flex items-center gap-3 justify-end" role="img" aria-label="percent {{ $pct }}%">
                    <div class="w-56 h-2 bg-gray-200 rounded">
                        <div class="h-2 rounded {{ $barClass }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <span>{{ $pct }}%</span>
                </div>
            </td>
            <td class="py-2 text-right">
                @if(is_null($impact)) -
                @else £{{ number_format((float) $impact, 2) }}
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>