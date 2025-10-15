@php
    $form    = $form    ?? null;
    $schema  = $schema  ?? [];
    $oldData = $oldData ?? [];

    $serviceFor = $serviceSlugForForm ?? ($session->service_slug ?? 'weight-management-service');
    $treatFor   = $treatmentSlugForForm ?? ($session->treatment_slug ?? 'mounjaro');

    // Resolve the form if not provided
    if (!$form) {
        $form = \App\Models\ClinicForm::query()
            ->where('form_type','declaration')
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
            'form_type'               => 'declaration',
            'service_slug'            => $serviceFor,
            'treatment_slug'          => $treatFor,
        ])->first();
        $oldData = $resp?->data ?? [];
    }

@endphp

@php
    // Prefill pharmacist details if fields exist and are empty
    if (is_array($schema)) {
        foreach ($schema as $i => $field) {
            $type = $field['type'] ?? 'text_input';
            $cfg  = (array)($field['data'] ?? []);
            $name = $field['name'] ?? ($type === 'text_block' ? 'block_'.$i : 'field_'.$i);
            $label = strtolower((string)($cfg['label'] ?? ($field['label'] ?? '')));

            if (!array_key_exists($name, $oldData) || $oldData[$name] === '' || $oldData[$name] === null) {
                // Pharmacist Name
                if (str_contains($label, 'pharmacist') && str_contains($label, 'name')) {
                    $oldData[$name] = 'Wasim Malik';
                }
                // GPhC number
                if (str_contains($label, 'gphc') || str_contains($label, 'gphc number') || str_contains($label, 'registration')) {
                    $oldData[$name] = '2066988';
                }
            }
        }
    }
@endphp

@once
<style>
  /* Card styling for Pharmacist Declaration */
  .pd-card { border-radius: 12px; border: 1px solid rgba(255,255,255,.08); padding: 20px; }
  .pd-card p { margin: .4rem 0 .9rem; line-height: 1.6; }
  .pd-card h1, .pd-card h2, .pd-card h3 { margin: .8rem 0 .5rem; }
  .pd-card label { display:block; margin: 18px 0 6px; font-weight: 600; color: #e5e7eb; }
  .pd-card label + input,
  .pd-card label + select,
  .pd-card label + textarea { margin-top: 6px; }
  .pd-card label:not(:first-of-type) { border-top: 1px solid rgba(255,255,255,.08); padding-top: 14px; }
  .pd-card input[type="text"],
  .pd-card input[type="number"],
  .pd-card input[type="date"],
  .pd-card select { width: 50%; min-width: 280px; max-width: 600px; padding: .65rem .8rem; border: 2px solid #6b7280; border-radius: 8px; color: #e5e7eb; outline: none; transition: border-color .2s ease, box-shadow .2s ease; }
  .pd-card input[type="text"]:hover,
  .pd-card input[type="number"]:hover,
  .pd-card input[type="date"]:hover,
  .pd-card select:hover { border-color: #f59e0b; }
  .pd-card input[type="text"]:focus,
  .pd-card input[type="number"]:focus,
  .pd-card input[type="date"]:focus,
  .pd-card select:focus { border-color: #fbbf24; box-shadow: 0 0 0 3px rgba(251,191,36,.25); }
  .pd-card textarea { width: 100%; min-height: 120px; border: 1px solid #374151; border-radius: 10px; color: #e5e7eb; padding: 12px 14px; }
  @media (max-width: 768px){ .pd-card input[type="text"], .pd-card input[type="number"], .pd-card input[type="date"], .pd-card select { width: 100%; } }
</style>
@endonce

@if(!$form)
    <div class="text-sm text-gray-400 p-4">
        No active Pharmacist Declaration form found for this session.
    </div>
@else
<div class="rounded-xl border border-gray-700 bg-gray-900/50 p-8 space-y-6 pd-card">
<form id="cf_pharmacist-declaration" method="POST"
      action="{{ url('/admin/consultations/' . $session->id . '/forms/' . $form->id . '/save') }}?tab=pharmacist-declaration" class="space-y-6">
    @csrf
    <input type="hidden" name="__step_slug" value="pharmacist-declaration">

    @foreach ($schema as $i => $field)
        @php
            $type = $field['type'] ?? 'text_input';
            $cfg  = (array)($field['data'] ?? []);
            $name = $field['name'] ?? ($type === 'text_block' ? 'block_'.$i : 'field_'.$i);
            $label = $cfg['label'] ?? ($field['label'] ?? ucfirst(str_replace('_',' ',$name)));
            $options = (array)($cfg['options'] ?? ($field['options'] ?? []));
            $content = $cfg['content'] ?? '';
            $val = old($name, $oldData[$name] ?? '');
        @endphp

        @if ($type === 'text_block')
            <div class="rounded-md border border-gray-700/60 bg-gray-900/40 p-6 mb-8 prose prose-invert max-w-none leading-relaxed">
                {!! $content !!}
            </div>
        @else
            <div class="pharm-field py-6 space-y-4">
                @php $isGphc = \Illuminate\Support\Str::contains($label, 'GPHC'); @endphp
                @if ($isGphc || $type === 'signature')
                    <div style="margin-top: 2rem; margin-bottom: 2rem; border-top: 1px solid rgba(255,255,255,0.1);"></div>
                @endif
                @if($label)
                    <label for="{{ $name }}" class="block text-base font-medium text-gray-200 mb-2">{{ $label }}</label>
                @endif

                @if ($type === 'select')
                    <select id="{{ $name }}" name="{{ $name }}" class="mt-1 block w-full rounded-md bg-gray-800 border border-gray-700 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600">
                        @foreach ($options as $ov => $ol)
                            @php
                                $optLabel = is_array($ol) ? ($ol['label'] ?? $ov) : $ol;
                                $optValue = is_array($ol) ? ($ol['value'] ?? $ov) : $ov;
                            @endphp
                            <option value="{{ $optValue }}" {{ (string)$val === (string)$optValue ? 'selected' : '' }}>
                                {{ $optLabel }}
                            </option>
                        @endforeach
                    </select>
                @elseif ($type === 'textarea')
                    <textarea id="{{ $name }}" name="{{ $name }}" rows="5" class="block w-full rounded-md bg-gray-900 border border-gray-600 hover:border-amber-500 text-gray-100 focus:outline-none focus:ring-2 focus:ring-amber-500 transition p-3">{{ $val }}</textarea>
                @elseif ($type === 'checkbox')
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" id="{{ $name }}" name="{{ $name }}" class="rounded bg-gray-800 border-gray-700" {{ (bool)$val ? 'checked' : '' }}>
                        <span class="text-sm text-gray-200">{{ $label }}</span>
                    </label>
                @elseif ($type === 'signature')
                    @php
                        $canvasId = 'sig_'.$name;
                        $hiddenId = 'sig_'.$name.'_input';
                    @endphp
                    <div class="space-y-3 mt-3">
                        <div class="text-xs text-gray-400">Draw your signature below</div>
                        <div class="rounded-md border border-gray-700 bg-gray-800/60 p-4">
                            <canvas id="{{ $canvasId }}" width="560" height="160" class="w-full max-w-full rounded" style="background:#ffffff;"></canvas>
                            <input type="hidden" id="{{ $hiddenId }}" name="{{ $name }}" value="{{ $val }}">
                            <div class="mt-2">
                                <x-filament::button type="button" size="xs" x-on:click="(function(){const c=document.getElementById('{{ $canvasId }}');const ctx=c.getContext('2d');ctx.clearRect(0,0,c.width,c.height);ctx.fillStyle='#ffffff';ctx.fillRect(0,0,c.width,c.height);document.getElementById('{{ $hiddenId }}').value='';})()">Clear</x-filament::button>
                            </div>
                        </div>
                    </div>
                    <script>
                        (function(){
                            const c = document.getElementById('{{ $canvasId }}');
                            if (!c) return;
                            const ctx = c.getContext('2d');
                            let drawing = false, prev = null;

                            // Always work on an opaque white background (important for PDFs)
                            function ensureWhiteBackground() {
                                // Only paint if the canvas is empty (all transparent) or after clearing
                                // We just paint white underneath
                                ctx.save();
                                ctx.globalCompositeOperation = 'destination-over';
                                ctx.fillStyle = '#ffffff';
                                ctx.fillRect(0, 0, c.width, c.height);
                                ctx.restore();
                            }

                            // Restore an existing signature (if any), then ensure white background behind it
                            (function restore(){
                                const existing = document.getElementById('{{ $hiddenId }}').value;
                                if (existing) {
                                    const img = new Image();
                                    img.onload = () => {
                                        ctx.drawImage(img, 0, 0);
                                        ensureWhiteBackground();
                                    };
                                    img.src = existing;
                                } else {
                                    // No existing image: start with white background
                                    ensureWhiteBackground();
                                }
                            })();

                            function pos(e){
                                const r = c.getBoundingClientRect();
                                const x = (e.touches ? e.touches[0].clientX : e.clientX) - r.left;
                                const y = (e.touches ? e.touches[0].clientY : e.clientY) - r.top;
                                return { x: x * (c.width / r.width), y: y * (c.height / r.height) };
                            }

                            function start(e){
                                drawing = true; prev = pos(e);
                                e.preventDefault();
                            }

                            function move(e){
                                if (!drawing) return;
                                const p = pos(e);
                                ctx.strokeStyle = '#000000';   // BLACK pen for better print visibility
                                ctx.lineWidth = 2.5;
                                ctx.lineCap = 'round';
                                ctx.beginPath();
                                ctx.moveTo(prev.x, prev.y);
                                ctx.lineTo(p.x, p.y);
                                ctx.stroke();
                                prev = p;
                                // Update hidden field during drawing
                                ensureWhiteBackground();
                                document.getElementById('{{ $hiddenId }}').value = c.toDataURL('image/png');
                                e.preventDefault();
                            }

                            function end(){
                                drawing = false; prev = null;
                                ensureWhiteBackground();
                                document.getElementById('{{ $hiddenId }}').value = c.toDataURL('image/png');
                            }

                            c.addEventListener('mousedown', start);
                            c.addEventListener('mousemove', move);
                            window.addEventListener('mouseup', end);

                            c.addEventListener('touchstart', start, {passive:false});
                            c.addEventListener('touchmove', move, {passive:false});
                            window.addEventListener('touchend', end, {passive:false});
                        })();
                    </script>
                @else
                    <input type="{{ $type === 'text_input' ? 'text' : $type }}" id="{{ $name }}" name="{{ $name }}" value="{{ $val }}" class="block w-1/2 rounded-md border-2 border-gray-500 hover:border-amber-500 focus:border-amber-400 text-gray-100 bg-transparent focus:outline-none focus:ring-2 focus:ring-amber-500 transition p-3 mt-2 mb-4" />
                @endif
            </div>
        @endif
    @endforeach

</form>
</div>
@endif

<style>
  /* extra spacing for inline checkbox rows within this form */
  #cf_pharmacist-declaration label input[type="checkbox"] { margin-right: .5rem; }
  #cf_pharmacist-declaration label { margin-bottom: .25rem; }

  .pharm-field label{display:block !important;}
  .pharm-field input[type="text"],
  .pharm-field input[type="number"]{
    display:block !important;
    width:50% !important;
    min-width:280px !important;
    max-width:600px !important;
    border:2px solid #6b7280 !important;
    border-radius:0.5rem !important;
    background-color:transparent !important;
    padding:0.75rem !important;
    transition:all 0.2s ease !important;
  }
  .pharm-field input[type="text"]:hover,
  .pharm-field input[type="number"]:hover{
    border-color:#f59e0b !important;
  }
  .pharm-field input[type="text"]:focus,
  .pharm-field input[type="number"]:focus{
    border-color:#fbbf24 !important;
    outline:none !important;
    box-shadow:0 0 0 3px rgba(251,191,36,0.3) !important;
  }
  .pharm-field textarea{display:block !important; width:100% !important;}
  .pharm-field + .pharm-field{margin-top:0 !important;}
</style>