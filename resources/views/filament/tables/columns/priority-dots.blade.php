@php
    /** @var \App\Models\Patient $record */
    $record  = $getRecord();
    $pid     = (int) ($record->id ?? 0);
    $current = strtolower((string) ($record->priority ?? 'green'));
    $current = in_array($current, ['red','yellow','green'], true) ? $current : 'green';

    $opts = [
        'red'    => '#ef4444',
        'yellow' => '#eab308',
        'green'  => '#22c55e',
    ];
@endphp

<div style="display:flex;gap:.5rem;align-items:center">
    @foreach ($opts as $key => $hex)
        @php
            $isActive = ($current === $key);
            $href = request()->fullUrlWithQuery(['pid' => $pid, 'set_priority' => $key]);
        @endphp
        <a href="{{ $href }}"
           title="{{ ucfirst($key) }}"
           style="display:inline-flex;width:20px;height:20px;border-radius:9999px;background:{{ $hex }};{{ $isActive ? 'outline:2px solid rgba(255,255,255,.95); outline-offset:2px;' : 'opacity:.7' }}">
        </a>
    @endforeach
</div>