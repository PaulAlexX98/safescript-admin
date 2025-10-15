@php
    $form    = $form    ?? null;
    $schema  = $schema  ?? [];
    $oldData = $oldData ?? [];

    $serviceFor = $serviceSlugForForm ?? ($session->service_slug ?? 'weight-management-service');
    $treatFor   = $treatmentSlugForForm ?? ($session->treatment_slug ?? 'mounjaro');

    // Resolve the form if not provided
    if (!$form) {
        $form = \App\Models\ClinicForm::query()
            ->where('form_type','advice')
            ->where('service_slug', $serviceFor)
            ->where('treatment_slug', $treatFor)
            ->where('is_active', 1)
            ->orderByDesc('version')
            ->first();
    }

    // Decode schema if needed
    if ((empty($schema) || !is_array($schema)) && $form) {
        $schema = is_array($form->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
    }

    // Prefill data from previous response
    if ((empty($oldData) || !is_array($oldData)) && $form) {
        $resp = \App\Models\ConsultationFormResponse::query()->where([
            'consultation_session_id' => $session->id,
            'form_type'               => 'advice',
            'service_slug'            => $serviceFor,
            'treatment_slug'          => $treatFor,
        ])->first();
        $oldData = $resp?->data ?? [];
    }
@endphp


@once
<style>
  /* Scoped spacing for rich HTML inside advice text blocks */
  .cf-rich h1, .cf-rich h2, .cf-rich h3, .cf-rich h4 { margin-top: 1rem; margin-bottom: 0.5rem; line-height: 1.3; }
  .cf-rich p { margin-top: 0.4rem; margin-bottom: 0.9rem; }
  .cf-rich ul, .cf-rich ol { margin-top: 0.5rem; margin-bottom: 1rem; padding-left: 1.25rem; }
  .cf-rich li { margin: 0.25rem 0; }
  .cf-rich a { text-decoration: underline; }
  .cf-notes { width: 100% !important; max-width: 100% !important; min-height: 6rem; margin-top: 0.75rem; }
</style>
@endonce

@once
<style>
  /* Card styling to match Record of Supply */
  .pa-card { border-radius: 12px; border: 1px solid rgba(255,255,255,.08); padding: 20px; }
  .pa-card p { margin: .4rem 0 .9rem; line-height: 1.6; }
  .pa-card h1, .pa-card h2, .pa-card h3 { margin: .8rem 0 .5rem; }
  /* Labels + gentle separators like RoS */
  .pa-card label { display:block; margin: 18px 0 6px; font-weight: 600; color: #e5e7eb; }
  .pa-card label + input,
  .pa-card label + select,
  .pa-card label + textarea { margin-top: 6px; }
  .pa-card label:not(:first-of-type) { border-top: 1px solid rgba(255,255,255,.08); padding-top: 14px; }
  /* Inputs: half-width desktop, full on small screens */
  .pa-card input[type="text"],
  .pa-card input[type="number"],
  .pa-card input[type="date"],
  .pa-card select { width: 50%; min-width: 280px; max-width: 600px; padding: .65rem .8rem; border: 2px solid #6b7280; border-radius: 8px; color: #e5e7eb; outline: none; transition: border-color .2s ease, box-shadow .2s ease; }
  .pa-card input[type="text"]:hover,
  .pa-card input[type="number"]:hover,
  .pa-card input[type="date"]:hover,
  .pa-card select:hover { border-color: #f59e0b; }
  .pa-card input[type="text"]:focus,
  .pa-card input[type="number"]:focus,
  .pa-card input[type="date"]:focus,
  .pa-card select:focus { border-color: #fbbf24; box-shadow: 0 0 0 3px rgba(251,191,36,.25); }
  .pa-card textarea { width: 100%; min-height: 120px; border: 1px solid #374151; border-radius: 10px; color: #e5e7eb; padding: 12px 14px; }
  @media (max-width: 768px){ .pa-card input[type="text"], .pa-card input[type="number"], .pa-card input[type="date"], .pa-card select { width: 100%; } }
  /* Divider used after the two guidance lines */
  .pa-divider { border-top: 1px solid rgba(255,255,255,.08); margin: 12px 0 4px; }
</style>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    const root = document.querySelector('.pa-card');
    if (!root) return;
    // Insert subtle divider lines after specific guidance phrases
    const phrases = [
      'please tick if discussed with the patient',
      'please follow your training and the relevant pgd guidance on how to prepare your selected medicine then supply it to the patient'
    ];
    const addDividerAfter = function(el){
      const hr = document.createElement('div');
      hr.className = 'pa-divider';
      el.insertAdjacentElement('afterend', hr);
    };
    root.querySelectorAll('p, h1, h2, h3, div').forEach(function(node){
      const txt = (node.textContent || '').toLowerCase().replace(/\s+/g,' ').trim();
      if (!txt) return;
      for (const phr of phrases) { if (txt.includes(phr)) { addDividerAfter(node); break; } }
    });
  });
</script>
@endonce


@if(!$form)
    <div class="text-sm text-gray-400 p-4">
        No active Pharmacist Advice form found for this session.
    </div>
@else
<div class="pa-card">
<form id="cf_pharmacist-advice" method="POST" action="{{ route('consultations.forms.save', ['session' => $session->id, 'form' => $form->id]) }}?tab=pharmacist-advice">
    @csrf
    <input type="hidden" name="__step_slug" value="pharmacist-advice">

    @foreach ($schema as $i => $field)
        @php
            $type = $field['type'] ?? 'text_input';
            $cfg  = (array)($field['data'] ?? []);
            $name = $field['name'] ?? ($type === 'text_block' ? 'block_'.$i : 'field_'.$i);
            $label = $cfg['label'] ?? ($field['label'] ?? ucfirst(str_replace('_',' ',$name)));
            $options = (array)($cfg['options'] ?? ($field['options'] ?? []));
            $content = $cfg['content'] ?? '';
            $desc    = $cfg['description'] ?? ($field['description'] ?? null);
            $isNotes = \Illuminate\Support\Str::contains(strtolower(($name.' '.($label ?? ''))), 'note');
            $rows    = (int)($cfg['rows'] ?? ($isNotes ? 8 : 5));
            $val     = old($name, $oldData[$name] ?? '');
        @endphp

        @if ($type === 'text_block')
            <div class="cf-rich rounded-xl bg-gray-900/60 ring-1 ring-white/10 p-6 md:p-8 mb-10 prose prose-invert max-w-none leading-7 prose-headings:mt-0 prose-headings:mb-3 prose-p:my-4 prose-ul:my-4 prose-ol:my-4 prose-li:my-2">
                {!! $content !!}
            </div>
        @else
            <div class="mb-10 {{ $isNotes ? '' : 'space-y-4' }}" @if($isNotes) style="margin-top: 2.5rem;" @endif>
                @if($label && $type !== 'checkbox' && !($type === 'text_input' && $isNotes))
                    <label for="{{ $name }}" class="block text-sm font-medium text-gray-200">{{ $label }}</label>
                    @if(!empty($desc))
                        <p class="text-sm text-gray-400">{!! $desc !!}</p>
                    @endif
                @endif

                @if ($type === 'select')
                    <select id="{{ $name }}" name="{{ $name }}" class="mt-1 block w-full rounded-lg bg-gray-800/70 border border-gray-700 text-gray-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                        @foreach ($options as $ov => $ol)
                            @php
                                $optLabel = is_array($ol) ? ($ol['label'] ?? $ov) : $ol;
                                $optValue = is_array($ol) ? ($ol['value'] ?? $ov) : $ov;
                            @endphp
                            <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>{{ $optLabel }}</option>
                        @endforeach
                    </select>
                @elseif ($type === 'textarea')
                    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" placeholder="{{ $isNotes ? 'Type consultation notes…' : '' }}" class="mt-1 mb-6 block w-full rounded-lg bg-gray-800/70 border border-gray-700 text-gray-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500">{{ $val }}</textarea>
                @elseif ($type === 'checkbox')
                    <label class="inline-flex items-center gap-2 mb-16" for="{{ $name }}">
                        <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="rounded-md bg-gray-800/70 border-gray-700 focus:ring-amber-500 focus:ring-2" {{ (bool)$val ? 'checked' : '' }}>
                        <span class="text-sm text-gray-200">{{ $label }}</span>
                    </label>
                    @if(!empty($desc))
                        <p class="text-sm text-gray-400">{!! $desc !!}</p>
                    @endif
                @else
                    @if ($type === 'text_input' && $isNotes)
                        <div class="mb-16">
                            @php
                                $orderRef = $session->order ?? null;
                                $metaRaw = $orderRef?->meta ?? [];
                                $metaArr = is_array($metaRaw) ? $metaRaw : (json_decode($metaRaw ?: '[]', true) ?: []);

                                // Primary source written by ApprovedOrderResource Action::make('addAdminNote')
                                $adminNote = data_get($metaArr, 'admin_notes');

                                // Fallbacks for other keys we might have used historically
                                if (empty($adminNote)) {
                                    $adminNote = $orderRef->admin_note
                                        ?? data_get($metaArr, 'admin_note')
                                        ?? data_get($metaArr, 'admin.notes')
                                        ?? data_get($metaArr, 'admin_note_text')
                                        ?? data_get($metaArr, 'note')
                                        ?? data_get($metaArr, 'notes')
                                        ?? data_get($metaArr, 'adminNote')
                                        ?? '';
                                }
                            @endphp

                            <div class="rounded-lg bg-amber-500/10 ring-1 ring-amber-500/30 p-6" style="margin-top: 3rem; margin-bottom: 2rem;">
                                <div class="text-sm font-semibold text-amber-300 mb-3">Admin notes</div>
                                @if(!empty($adminNote))
                                    @php
                                        $notesArray = preg_split('/\r?\n/', trim($adminNote));
                                    @endphp
                                    <ul class="list-disc list-inside space-y-2 text-sm text-amber-100">
                                        @foreach($notesArray as $noteLine)
                                            <li class="leading-6">{{ trim($noteLine) }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="text-sm text-amber-200">No admin note on this order</div>
                                @endif
                            </div>
                            <div style="height: 24px;"></div>

                            <label for="{{ $name }}" class="block text-base font-semibold text-gray-100 mb-3" style="margin-top: 1rem;">Consultation Notes</label>
                            <div class="w-full max-w-none">
                                <textarea id="{{ $name }}" name="{{ $name }}" rows="4" placeholder="Write detailed consultation notes here..." class="cf-notes mt-3 block w-full max-w-none rounded-lg bg-gray-900/70 border border-gray-700 text-gray-100 placeholder-gray-500 p-4 text-base leading-7 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500 resize-y shadow-sm mb-12">{{ $val }}</textarea>
                            </div>
                            <div style="margin-top: 1.25rem; margin-bottom: 1.25rem; border-top: 1px solid rgba(255,255,255,0.1);"></div>
                        </div>
                    @else
                        <input type="{{ $type === 'text_input' ? 'text' : $type }}" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" class="mt-1 mb-6 block w-full rounded-lg bg-gray-800/70 border border-gray-700 text-gray-100 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-amber-500" />
                    @endif
                @endif
            </div>
        @endif
    @endforeach

    <div class="mt-20 pt-10 border-t border-white/10 flex items-center justify-end">
        <label class="inline-flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="tick_all" class="rounded-md bg-gray-800/70 border-gray-700 focus:ring-amber-500 focus:ring-2">
            <span class="text-sm text-gray-200">Tick all checkboxes</span>
        </label>
    </div>

</form>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    const tickAll = document.getElementById('tick_all');
    if (tickAll) {
      tickAll.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('#cf_pharmacist-advice input[type="checkbox"]');
        checkboxes.forEach(cb => {
          if (cb !== tickAll) cb.checked = tickAll.checked;
        });
      });
    }
  });
</script>
@endif