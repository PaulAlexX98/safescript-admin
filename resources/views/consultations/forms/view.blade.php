@extends('filament::components.layouts.app') {{-- or your admin layout --}}

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
  <div class="flex items-center justify-between">
    <h1 class="text-xl font-semibold">{{ $title }}</h1>
    <div class="flex gap-2">
      <a href="{{ route('admin.consultations.forms.edit', [$resp->consultation_session_id, $resp->id]) }}" class="px-3 py-2 rounded-md bg-warning-600 text-white text-sm">Edit</a>
      <a href="{{ route('admin.consultations.forms.history', [$resp->consultation_session_id, $resp->id]) }}" class="px-3 py-2 rounded-md bg-gray-700 text-white text-sm">History</a>
    </div>
  </div>

  <div class="rounded-xl border border-gray-700/60 bg-gray-800/40 p-5">
    <div class="text-xs text-gray-400 mb-4">Submitted {{ optional($resp->created_at)->format('d-m-Y H:i') }}</div>

    @php
      $pairs = [];
      foreach ($data as $k => $v) {
        if (is_array($v)) $v = implode(', ', array_map('strval', $v));
        $pairs[] = [ucwords(str_replace(['_', '-'], ' ', (string)$k)), (string)$v];
      }
    @endphp

    @if(empty($pairs))
      <div class="text-sm text-gray-400">No captured fields.</div>
    @else
      <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-3">
        @foreach($pairs as [$k,$v])
          <div class="border-b border-gray-700/50 pb-2">
            <dt class="text-xs text-gray-400">{{ $k }}</dt>
            <dd class="text-sm text-gray-100">{!! nl2br(e($v)) !!}</dd>
          </div>
        @endforeach
      </dl>
    @endif
  </div>
</div>
@endsection