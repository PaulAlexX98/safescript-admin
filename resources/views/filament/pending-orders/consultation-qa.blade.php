@php
$forms = is_array($state ?? null) ? $state : [];
$sections = ['raf' => 'RAF'];
@endphp

<div class="space-y-8">
  @forelse($sections as $key => $title)
    @php $qa = $forms[$key]['qa'] ?? []; @endphp
    <div class="rounded-lg border p-4">
      <h3 class="font-semibold mb-3">{{ $title }}</h3>
      @if(empty($qa))
        <div class="text-sm text-gray-500">No answers captured</div>
      @else
        <dl class="divide-y">
          @foreach($qa as $row)
            <div class="py-2 grid grid-cols-3 gap-3">
              <dt class="text-sm font-medium">{{ $row['question'] ?? $row['key'] ?? 'Question' }}</dt>
              <dd class="col-span-2 text-sm break-words">
                @php $ans = $row['answer'] ?? ''; @endphp
                @if(is_string($ans) && str_starts_with($ans, '{'))
                  {{-- file or complex JSON answer fallback to filename --}}
                  @php $raw = $row['raw'] ?? null;
                       $name = is_array($raw) && isset($raw[0]['name']) ? $raw[0]['name'] : $ans; @endphp
                  {{ $name }}
                @else
                  {{ $ans }}
                @endif
              </dd>
            </div>
          @endforeach
        </dl>
      @endif
    </div>
  @empty
    <div class="text-sm text-gray-500">No consultation snapshot available</div>
  @endforelse
</div>