<div
  x-data="{}"
  x-on:click.capture="
    const a = $event.target.closest('a[data-inline-modal]');
    if(!a) return;
    // respect modifier clicks (open in new tab, etc.)
    if ($event.button !== 0 || $event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey) return;
    $event.preventDefault();
    const t = a.getAttribute('data-title') || 'Form';
    const raw = a.getAttribute('data-url') || a.getAttribute('href') || '';
    const url = raw.includes('inline=1') ? raw : (raw + (raw.includes('?') ? '&' : '?') + 'inline=1');
    if (window.__openConsultationModal) { window.__openConsultationModal(t, url); } else { window.location.href = url; }
  "
>
  @php
    // Ensure we have $forms and $session in scope; if not, try to resolve by common names
    if (!isset($forms) || !($forms instanceof \Illuminate\Support\Collection)) {
        $sidGuess = $session->id ?? ($consultation->id ?? null);
        $forms = $sidGuess ? \App\Models\ConsultationFormResponse::where('consultation_session_id', $sidGuess)->latest()->get() : collect();
    }
  @endphp

  @if($forms->isEmpty())
    <p class="text-sm text-gray-400">No submitted forms yet.</p>
  @else
    @foreach($forms as $f)
      @php
        $clinicForm = \App\Models\ClinicForm::find($f->clinic_form_id);
        $title = $clinicForm?->name ?: ('Form #'.$f->id);
        $sid   = isset($session) ? $session->id : ($f->consultation_session_id ?? null);
        $fid   = $f->id; // ConsultationFormResponse id
        $view  = $sid ? "/admin/consultations/{$sid}/forms/{$fid}/view"    : '#';
        $edit  = $sid ? "/admin/consultations/{$sid}/forms/{$fid}/edit"    : '#';
        $hist  = $sid ? "/admin/consultations/{$sid}/forms/{$fid}/history" : '#';
      @endphp

      <div class="rounded-xl border border-white/10 bg-gray-900/60 p-4 mb-3">
        <div class="flex items-start justify-between gap-3 mb-3">
          <div class="text-sm text-gray-200">{{ $title }}</div>
          <div class="text-xs text-gray-400">ID: {{ $f->id }}</div>
        </div>

        <div class="flex flex-wrap gap-2">
          <a href="{{ $view }}" data-inline-modal data-title="View — {{ $title }}"
             class="px-3 py-2 rounded-md bg-white/10 hover:bg-white/20 text-white text-sm">View</a>

          <a href="{{ $edit }}" data-inline-modal data-title="Edit — {{ $title }}"
             class="px-3 py-2 rounded-md bg-white/10 hover:bg-white/20 text-white text-sm">Edit</a>

          <a href="{{ $hist }}" data-inline-modal data-title="History — {{ $title }}"
             class="px-3 py-2 rounded-md bg-white/10 hover:bg-white/20 text-white text-sm">History</a>
        </div>
      </div>
    @endforeach
  @endif
</div>