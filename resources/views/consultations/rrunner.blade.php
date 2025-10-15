<x-filament-panels::page>
    @php
        // Build tabs from $steps (fallback to a sensible set)
        $rawSteps = is_countable($steps ?? []) && count($steps) ? (array) $steps : ['advice','declaration','record of supply'];
        // If `$steps` came in as a single combined string (e.g. "advice declaration supply" or "Advice, Declaration | Supply"),
        // split it into known steps so each becomes its own tab.
        if (count($rawSteps) === 1 && is_string($rawSteps[0])) {
            $one = \Illuminate\Support\Str::of((string) $rawSteps[0])->lower()->toString();

            // 1) quick path: if it already obviously contains multiple words, split by common separators
            $tokens = preg_split('/[\s,|\/]+/u', $one, -1, PREG_SPLIT_NO_EMPTY);

            // 2) Map tokens and also do keyword-detection as a fallback
            $mapToken = function (string $t): ?string {
                $t = trim($t);
                if ($t === '') return null;

                if (str_contains($t, 'advice')) return 'advice';
                if (str_contains($t, 'declar')) return 'declaration';
                if (str_contains($t, 'record') && str_contains($t, 'supply')) return 'record of supply';
                if (str_contains($t, 'supply')) return 'record of supply';
                if (str_contains($t, 'raf')) return 'raf';

                return null;
            };

            $guessed = [];
            foreach ($tokens as $tk) {
                $norm = $mapToken($tk);
                if ($norm) $guessed[] = $norm;
            }

            // 3) strict fallback: keyword scan across the whole string if tokenisation failed
            if (empty($guessed)) {
                if (str_contains($one, 'advice')) $guessed[] = 'advice';
                if (str_contains($one, 'declar')) $guessed[] = 'declaration';
                if (str_contains($one, 'record of supply')) $guessed[] = 'record of supply';
                elseif (str_contains($one, 'supply')) $guessed[] = 'record of supply';
                if (str_contains($one, 'raf')) $guessed[] = 'raf';
            }

            // 4) Deduplicate while preserving order and ensure we found something
            if (!empty($guessed)) {
                $rawSteps = array_values(array_unique($guessed));
            }
        }
        // Temporarily disable RAF step
        $rawSteps = array_values(array_filter($rawSteps, function ($s) {
            $slug = \Illuminate\Support\Str::slug(is_string($s) ? $s : (string) $s);
            return $slug !== 'raf';
        }));
        $tabs = [];
        foreach ($rawSteps as $s) {
            $label = trim(is_string($s) ? $s : (string) $s);
            $slug  = \Illuminate\Support\Str::slug($label);
            $tabs[$slug] = strtoupper($label);
        }
        $reqTabRaw = request('tab');
        if (!$reqTabRaw && isset($_GET['tab'])) {
            $reqTabRaw = $_GET['tab'];
        }
        $reqTab = \Illuminate\Support\Str::slug((string) $reqTabRaw);
        $activeSlug = array_key_first($tabs);
        if ($reqTab) {
            if (isset($tabs[$reqTab])) {
                $activeSlug = $reqTab;
            } else {
                // fuzzy match: contains either way (e.g. declaration vs pharmacist-declaration)
                $maybe = collect(array_keys($tabs))->first(function ($k) use ($reqTab) {
                    return \Illuminate\Support\Str::contains($k, $reqTab) || \Illuminate\Support\Str::contains($reqTab, $k);
                });
                if ($maybe) {
                    $activeSlug = $maybe;
                } else {
                    // common aliasing
                    if ($reqTab === 'declaration') {
                        $maybe = collect(array_keys($tabs))->first(fn ($k) => \Illuminate\Support\Str::contains($k, 'declar'));
                        if ($maybe) { $activeSlug = $maybe; }
                    } elseif ($reqTab === 'record-of-supply' || $reqTab === 'supply') {
                        $maybe = collect(array_keys($tabs))->first(fn ($k) => \Illuminate\Support\Str::contains($k, 'supply'));
                        if ($maybe) { $activeSlug = $maybe; }
                    }
                }
            }
        }

        // Convenience for ClinicForm lookup
        $serviceNameLocal = $serviceName ?? ($service ?? null);
        $treatmentLocal   = $treatment ?? ($treat ?? null);

        // Prefer canonical slugs from the session if available
        $serviceSlugForForm   = $session->service_slug   ?? ($serviceNameLocal ? \Illuminate\Support\Str::slug($serviceNameLocal)   : null);
        $treatmentSlugForForm = $session->treatment_slug ?? ($treatmentLocal   ? \Illuminate\Support\Str::slug($treatmentLocal)     : null);
    @endphp

    <x-filament::card class="rounded-xl">
        <div
            x-data="{ tab: '{{ $activeSlug }}', order: @js(array_keys($tabs)) }"
            x-init="
                // 0) Respect tab from URL on first load and normalize aliases
                (function(){
                    const u = new URL(window.location);
                    let qt = (u.searchParams.get('tab') || '').toLowerCase();
                    if (qt) {
                        // map common aliases to actual keys in 'order'
                        if (!order.includes(qt)) {
                            if (qt === 'supply' || qt === 'record-of-supply') {
                                const k = order.find(k => k.includes('supply')) || order.find(k => k.includes('record'));
                                if (k) qt = k;
                            } else if (qt === 'declaration' || qt.includes('declar')) {
                                const k = order.find(k => k.includes('declar'));
                                if (k) qt = k;
                            }
                        }
                        if (order.includes(qt)) {
                            tab = qt;
                        }
                    }
                })();
                // 1) Keep URL in sync when switching tabs
                $watch('tab', t => { const u=new URL(window.location); u.searchParams.set('tab', t); history.replaceState({},'',u) });
                // 2) After Save & Next, jump to next tab once
                if (sessionStorage.getItem('consult_next') === '1') {
                    const i = order.indexOf(tab);
                    if (i >= 0 && i < order.length - 1) { tab = order[i + 1]; }
                    sessionStorage.removeItem('consult_next');
                }
            "
        >
            <div class="border-b border-gray-800 flex flex-wrap gap-1">
                @foreach ($tabs as $slug => $label)
                    <a
                        href="?tab={{ $slug }}"
                        @click.prevent="tab='{{ $slug }}'"
                        class="px-4 py-2 text-xs font-medium rounded-t-md transition"
                        :class="tab === '{{ $slug }}'
                            ? 'text-white bg-gray-900 border-x border-t border-gray-800 -mb-px'
                            : 'text-gray-400 hover:text-gray-200'">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
            @if (app()->environment('local') || request()->has('debug'))
                <div class="mt-3 text-xs text-gray-400">
                    Debug on  active tab <code>{{ $activeSlug }}</code>  all tabs <code>{{ implode(', ', array_keys($tabs)) }}</code>
                    service <code>{{ $serviceSlugForForm ?? 'null' }}</code>  treatment <code>{{ $treatmentSlugForForm ?? 'null' }}</code>
                </div>
            @endif

            <div class="mt-4">
                @foreach ($tabs as $slug => $label)
                    <div x-show="tab === '{{ $slug }}'" x-cloak>
                        @php
                            $normSlug = \Illuminate\Support\Str::of($slug)->lower()->toString();
                            $typeForForm = match (true) {
                                str_contains($normSlug, 'advice') => 'advice',
                                str_contains($normSlug, 'declar') => 'declaration',
                                str_contains($normSlug, 'supply') => 'supply',
                                default => \Illuminate\Support\Str::of($slug)->replace('record-of-supply', 'supply')->toString(),
                            };
                            /** @var \App\Models\ClinicForm|null $clinicForm */
                            $qBase = \App\Models\ClinicForm::query()
                                ->when(method_exists(\App\Models\ClinicForm::class, 'scopeOfType'), fn ($q) => $q->ofType($typeForForm), fn ($q) => $q->where('form_type', $typeForForm));

                            // 1) Strict match with active + service + treatment if available
                            $clinicForm = (clone $qBase)
                                ->where('is_active', true)
                                ->when($serviceSlugForForm, fn ($q) => $q->forService($serviceSlugForForm))
                                ->when($treatmentSlugForForm, fn ($q) => $q->forTreatment($treatmentSlugForForm))
                                ->orderByDesc('version')
                                ->first();

                            // 2) Relax active scope if nothing found
                            if (!$clinicForm) {
                                $clinicForm = (clone $qBase)
                                    ->when($serviceSlugForForm, fn ($q) => $q->forService($serviceSlugForForm))
                                    ->when($treatmentSlugForForm, fn ($q) => $q->forTreatment($treatmentSlugForForm))
                                    ->orderByDesc('version')
                                    ->first();
                            }

                            // 3) Fall back to latest of this type only, ignoring active
                            if (!$clinicForm) {
                                $clinicForm = (clone $qBase)
                                    ->orderByDesc('version')
                                    ->first();
                            }

                            // 4) Last-resort fuzzy name search
                            if (!$clinicForm) {
                                $clinicForm = \App\Models\ClinicForm::query()
                                    ->where('is_active', true)
                                    ->where(function($q) use ($typeForForm) {
                                        $q->where('form_type', $typeForForm)
                                          ->orWhere('name', 'like', '%'.($typeForForm === 'supply' ? 'supply' : $typeForForm).'%');
                                    })
                                    ->orderByDesc('version')
                                    ->first();
                            }

                            // 5) Absolute final guard: fetch latest by type only, ignoring all filters
                            if (!$clinicForm) {
                                $clinicForm = \App\Models\ClinicForm::query()
                                    ->where('form_type', $typeForForm)
                                    ->orderByDesc('version')
                                    ->first();
                            }

                            // Decode schema whether stored as array or JSON string
                            $schemaDecoded = [];
                            if ($clinicForm) {
                                $rawSchema = $clinicForm->schema ?? null;
                                if (is_array($rawSchema)) {
                                    $schemaDecoded = $rawSchema;
                                } elseif (is_string($rawSchema)) {
                                    $decoded = json_decode($rawSchema, true);
                                    if (is_string($decoded)) {
                                        $decoded = json_decode($decoded, true);
                                    }
                                    $schemaDecoded = is_array($decoded) ? $decoded : [];
                                }
                            }
                        @endphp
                        <div class="mb-3 text-xs text-gray-300">
                            type <code>{{ $typeForForm }}</code>
                            form_id <code>{{ $clinicForm?->id ?? 'none' }}</code>
                            schema_count <code>{{ is_array($schemaDecoded ?? null) ? count($schemaDecoded) : 0 }}</code>
                        </div>
                        @if($clinicForm && count($schemaDecoded))
                            @php
                                // Data bundle passed to your custom form partial with broad aliases
                                $__formPayload = [
                                    // canonical
                                    'session'        => $session,
                                    'clinicForm'     => $clinicForm,
                                    'schema'         => $schemaDecoded,
                                    'slug'           => $slug,
                                    'label'          => $label,
                                    'serviceSlug'    => $serviceSlugForForm ?? null,
                                    'treatmentSlug'  => $treatmentSlugForForm ?? null,
                                    'patientContext' => $patientContext ?? null,
                                    // common aliases expected by custom partials
                                    'form'           => $clinicForm,
                                    'form_id'        => $clinicForm?->id,
                                    'formType'       => $clinicForm?->form_type,
                                    'type'           => $clinicForm?->form_type,
                                    'fields'         => $schemaDecoded,
                                    'schema_json'    => $schemaDecoded,
                                    'step_slug'      => $slug,
                                    'active_tab'     => $slug,
                                    'stepLabel'      => $label,
                                    'service'        => $serviceSlugForForm ?? null,
                                    'treatment'      => $treatmentSlugForForm ?? null,
                                ];
                            @endphp

                            @if (view()->exists('consultations._form'))
                                {{-- Use your custom form partial if present --}}
                                @include('consultations._form', $__formPayload)
                                @continue
                            @endif

                            {{-- Fallback: built-in minimal renderer (unchanged below) --}}
                            @php
                                $existingResp = \App\Models\ConsultationFormResponse::where([
                                    'consultation_session_id' => $session->id,
                                    'form_type'               => $clinicForm->form_type,
                                    'service_slug'            => $clinicForm->service_slug,
                                    'treatment_slug'          => $clinicForm->treatment_slug,
                                ])->first();
                                $oldData = $existingResp?->data ?? [];
                            @endphp

                            <form id="consult-form-{{ $slug }}" x-ref="form" method="POST" action="{{ route('consultations.forms.save', [$session->id, $clinicForm->id]) }}?tab={{ $slug }}" class="space-y-4">
                                @csrf
                                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                <input type="hidden" name="__step_slug" value="{{ $slug }}">
                                @if (!empty($patientContext ?? ''))
                                    <input type="hidden" name="__patient_context" value="{{ $patientContext }}">
                                @endif
                                <input type="hidden" x-ref="goNext" name="__go_next" value="0">

                                @foreach($schemaDecoded as $field)
                                    @php
                                        $idx    = $loop->index;
                                        $fType  = \Illuminate\Support\Arr::get($field, 'type', 'text');
                                        $cfg    = (array) \Illuminate\Support\Arr::get($field, 'data', []);

                                        $fName  = \Illuminate\Support\Arr::get($field, 'name');
                                        if (!$fName) {
                                            $fName = $fType === 'text_block' ? ('discussed_block_'.$idx) : ('field_'.$idx);
                                        }

                                        $fLabel = $cfg['label'] ?? \Illuminate\Support\Arr::get($field, 'label');
                                        if (!$fLabel) {
                                            $fLabel = $fType === 'text_block' ? ('Discussed: Block '.($idx+1)) : (\Illuminate\Support\Str::headline($fName));
                                        }

                                        $fHelp  = $cfg['help'] ?? \Illuminate\Support\Arr::get($field, 'help');
                                        $fOpts  = $cfg['options'] ?? \Illuminate\Support\Arr::get($field, 'options', []);
                                        $fReq   = (bool) ($cfg['required'] ?? \Illuminate\Support\Arr::get($field, 'required', false));

                                        $val    = old($fName, \Illuminate\Support\Arr::get($oldData, $fName));
                                    @endphp

                                    @php
                                        $lblNorm = \Illuminate\Support\Str::of((string)$fLabel)->lower()->trim()->value();
                                        $nameNorm = \Illuminate\Support\Str::of((string)$fName)->lower()->trim()->value();
                                        $isConsultNotes = ($lblNorm === 'consultation notes' || $nameNorm === 'consultation_notes'
                                            || (str_contains($lblNorm, 'consultation') && str_contains($lblNorm, 'note'))
                                            || (str_contains($nameNorm, 'consultation') && str_contains($nameNorm, 'note')));
                                    @endphp

                                    @php
                                        $outerClasses = 'space-y-2 mb-4';
                                        $outerStyle = '';
                                        if ($fType === 'checkbox') {
                                            $outerClasses = 'space-y-2';
                                            $outerStyle = 'margin-bottom: 24px;';
                                        } elseif ($isConsultNotes) {
                                            $outerClasses = 'space-y-2';
                                            $outerStyle = 'margin: 16px 0 24px 0;';
                                        }
                                    @endphp
                                    <div class="{{ $outerClasses }}" @if($outerStyle) style="{{ $outerStyle }}" @endif>
                                        @php
                                            $labelInlineStyle = $isConsultNotes ? 'display:block;width:100%; margin-bottom: 12px;' : 'display:block;width:100%;';
                                        @endphp
                                        @if(!in_array($fType, ['checkbox','text_block']))
                                            <label class="block text-sm font-medium text-gray-300 mb-2" style="{{ $labelInlineStyle }}" for="cf_{{ $slug }}_{{ $fName }}">
                                                {{ $fLabel }} @if($fReq)<span class="text-red-500">*</span>@endif
                                            </label>
                                        @endif

                                        @switch($fType)
                                            @case('text_block')
                                                @php
                                                    $content   = $cfg['content'] ?? '';
                                                    $alignment = $cfg['align'] ?? 'left';
                                                    $hasBlockHtml = preg_match('/<(p|div|br|ul|ol|li|h[1-6]|table|section|article|blockquote|hr)\b/i', (string) $content) === 1;
                                                    if (!$hasBlockHtml) {
                                                        $raw = trim((string) $content);
                                                        $raw = preg_replace('/([a-z0-9])\.\s*(?=[A-Z])/', '$1.<br><br>', $raw);
                                                        $raw = preg_replace('/([:;])\s*(?=[A-Z])/', '$1<br><br>', $raw);
                                                        $parts = preg_split('/(<br><br>|\r?\n){1,}/', $raw) ?: [$raw];
                                                        $built = [];
                                                        foreach ($parts as $p) {
                                                            $p = trim($p);
                                                            if ($p === '') continue;
                                                            $p = preg_replace('/(\r?\n)/', '<br>', $p);
                                                            $built[] = '<p class="mb-10 leading-[1.95]">' . $p . '</p>';
                                                        }
                                                        $renderedHtml = implode("\n", $built);
                                                    } else {
                                                        $normalized = (string) $content;
                                                        $normalized = preg_replace('/(<br\s*\/?>(\s*)){2,}/i', '<br><br>', $normalized);
                                                        $normalized = preg_replace('/<p(?![^>]*\bstyle=)([^>]*)>/i', '<p$1 style="margin: 0 0 20px 0; line-height: 1.9;">', $normalized);
                                                        $normalized = preg_replace('/<h([1-6])(?![^>]*\bstyle=)([^>]*)>/i', '<h$1$2 style="margin: 0 0 18px 0;">', $normalized);
                                                        $normalized = preg_replace('/<(ul|ol)(?![^>]*\bstyle=)([^>]*)>/i', '<$1$2 style="margin: 0 0 18px 1.25rem;">', $normalized);
                                                        $normalized = preg_replace('/<li(?![^>]*\bstyle=)([^>]*)>/i', '<li$1 style="margin: 6px 0;">', $normalized);
                                                        $normalized = preg_replace('/(<input[^>]*type=\"checkbox\"[^>]*>\s*<[^>]+>[^<]*<\/label>)/i', '$1<br><br>', $normalized);
                                                        $normalized = preg_replace('/(<input[^>]*type=\"checkbox\"[^>]*>)/i', '$1<br><br>', $normalized);
                                                        $renderedHtml = $normalized;
                                                    }
                                                @endphp
                                                <div class="rounded-md border border-gray-700/60 bg-gray-900/50 p-8 mb-14">
                                                    <div class="prose prose-invert max-w-none leading-[1.95] [&>*+*]:mt-6 [&>h1]:mb-4 [&>h2]:mb-4 [&>h3]:mb-3 [&>p]:mb-0" style="text-align: {{ e($alignment) }};">
                                                        {!! $renderedHtml !!}
                                                    </div>
                                                </div>
                                                @break

                                            @case('textarea')
                                                <textarea id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-3 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600 block" rows="6" style="min-height: 140px; width: 100%; max-width: 100%; margin-top: 8px;" placeholder=" ">{{ $val }}</textarea>
                                                @break

                                            @case('select')
                                                <select id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-2 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600">
                                                    @foreach((array) $fOpts as $ov => $ol)
                                                        @php
                                                            $optLabel = is_array($ol) ? ($ol['label'] ?? $ov) : $ol;
                                                            $optValue = is_array($ol) ? ($ol['value'] ?? $ov) : $ov;
                                                        @endphp
                                                        <option value="{{ $optValue }}" @selected((string)$val === (string)$optValue)>{{ $optLabel }}</option>
                                                    @endforeach
                                                </select>
                                                @break

                                            @case('checkbox')
                                                <label class="inline-flex items-center gap-2 text-sm text-gray-200">
                                                    <input type="checkbox" id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="rounded bg-gray-800 border-gray-600" @checked((bool)$val)>
                                                    <span>{{ $fLabel }} @if($fReq)<span class="text-red-500">*</span>@endif</span>
                                                </label>
                                                <div style="height: 8px;"></div>
                                                @break

                                            @case('date')
                                                <input type="date" id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-2 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600" value="{{ \Illuminate\Support\Str::of((string)($val ?? ($cfg['date'] ?? '')))->limit(10,'') }}">
                                                @break

                                            @case('text_input')
                                                @php $isNotes = $isConsultNotes; @endphp
                                                @if($isNotes)
                                                    <textarea id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-3 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600 block" rows="6" style="min-height: 140px; width: 100%; max-width: 100%; margin-top: 8px;" placeholder="Enter consultation notes...">{{ $val }}</textarea>
                                                @else
                                                    <input type="text" id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-3 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600" value="{{ $val }}" placeholder="{{ $cfg['placeholder'] ?? 'Enter notes here' }}">
                                                @endif
                                                @break

                                            @case('number')
                                                <input type="number" id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-2 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600" value="{{ $val }}" @if(isset($cfg['min'])) min="{{ $cfg['min'] }}" @endif @if(isset($cfg['max'])) max="{{ $cfg['max'] }}" @endif>
                                                @break

                                            @case('signature')
                                                @php
                                                    $canvasId = "sig_{$slug}_{$fName}";
                                                    $hiddenId = "sig_{$slug}_{$fName}_input";
                                                @endphp
                                                <div class="space-y-2">
                                                    @if($fLabel)
                                                        <label class="block text-sm font-medium text-gray-300 mb-2" for="{{ $canvasId }}">
                                                            {{ $fLabel }} @if($fReq)<span class="text-red-500">*</span>@endif
                                                        </label>
                                                    @endif
                                                    <div class="text-xs text-gray-400">Draw your signature below</div>
                                                    <canvas id="{{ $canvasId }}" width="560" height="160" class="w-full max-w-full rounded-md border border-gray-600 bg-gray-800"></canvas>
                                                    <input type="hidden" id="{{ $hiddenId }}" name="{{ $fName }}" value="{{ $val }}">
                                                    <div class="flex gap-2">
                                                        <x-filament::button type="button" size="xs" x-on:click="(function(){const c=document.getElementById('{{ $canvasId }}');const ctx=c.getContext('2d');ctx.clearRect(0,0,c.width,c.height);document.getElementById('{{ $hiddenId }}').value='';})()">Clear</x-filament::button>
                                                    </div>
                                                </div>
                                                <script>
                                                    (function(){
                                                        const c = document.getElementById('{{ $canvasId }}');
                                                        if (!c) return;
                                                        const ctx = c.getContext('2d');
                                                        let drawing=false, prev=null;
                                                        (function restore(){
                                                            const existing = document.getElementById('{{ $hiddenId }}').value;
                                                            if (existing) { const img = new Image(); img.onload=()=>ctx.drawImage(img,0,0); img.src=existing; }
                                                        })();
                                                        function pos(e){ const r=c.getBoundingClientRect(); const x=(e.touches?e.touches[0].clientX:e.clientX)-r.left; const y=(e.touches?e.touches[0].clientY:e.clientY)-r.top; return { x: x*(c.width/r.width), y: y*(c.height/r.height) }; }
                                                        function start(e){ drawing=true; prev=pos(e); e.preventDefault(); }
                                                        function move(e){ if(!drawing) return; const p=pos(e); ctx.strokeStyle='#fff'; ctx.lineWidth=2; ctx.lineCap='round'; ctx.beginPath(); ctx.moveTo(prev.x,prev.y); ctx.lineTo(p.x,p.y); ctx.stroke(); prev=p; document.getElementById('{{ $hiddenId }}').value=c.toDataURL('image/png'); e.preventDefault(); }
                                                        function end(){ drawing=false; prev=null; }
                                                        c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
                                                        c.addEventListener('touchstart', start, {passive:false}); c.addEventListener('touchmove', move, {passive:false}); window.addEventListener('touchend', end, {passive:false});
                                                    })();
                                                </script>
                                                @break

                                            @default
                                                <input type="text" id="cf_{{ $slug }}_{{ $fName }}" name="{{ $fName }}" class="w-full rounded-md bg-gray-800 border border-gray-600 p-2 text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-600" value="{{ $val }}" placeholder=" ">
                                        @endswitch

                                        @if($fHelp)
                                            <p class="text-xs text-gray-400">{{ $fHelp }}</p>
                                        @endif
                                    </div>
                                @endforeach

                                @if($existingResp)
                                    <div class="space-y-1">
                                        <label class="block text-sm text-gray-300">Reason for changes (optional)</label>
                                        <input type="text" name="__reason" class="w-full rounded-md bg-gray-900 border border-gray-700 p-2 text-gray-100" placeholder="e.g., corrected value, added missing info">
                                    </div>
                                @endif

                                <div class="pt-2 flex gap-2">
                                    <x-filament::button type="submit" size="sm" color="primary" form="consult-form-{{ $slug }}">Save</x-filament::button>
                                    <x-filament::button type="button" size="sm" color="success" form="consult-form-{{ $slug }}" x-on:click="$refs.goNext.value='1'; sessionStorage.setItem('consult_next','1'); document.getElementById('consult-form-{{ $slug }}').submit();">Save &amp; Next</x-filament::button>
                                </div>
                            </form>
                            @continue
                        @endif

                        @if($clinicForm && (!is_array($clinicForm->schema) || count($clinicForm->schema) === 0))
                            <div class="rounded-md border border-amber-700/50 bg-amber-900/20 p-4 text-sm text-amber-200">
                                The form <strong>{{ $clinicForm->name }}</strong> exists but has no fields (empty schema).
                                <a href="{{ url('/admin/clinic-forms/'.$clinicForm->id.'/edit') }}" class="underline underline-offset-2">Add fields now</a>.
                            </div>
                            @continue
                        @endif

                        @php
                            // Prefer explicit tabs directory and pharmacist-* naming
                            $normSlug = \Illuminate\Support\Str::of($slug)->lower()->toString();
                            $slugForTabInclude = match (true) {
                                $normSlug === 'supply' => 'record-of-supply',
                                default => $normSlug,
                            };

                            $labelKebab = (string) \Illuminate\Support\Str::of($label)->lower()->replace(' ', '-');
                            $labelSnake = (string) \Illuminate\Support\Str::of($label)->snake();

                            $candidates = [
                                // tabs first
                                'consultations.tabs.' . $slugForTabInclude,
                                'consultations.tabs.pharmacist-' . $slugForTabInclude,
                                'consultations.tabs.' . $labelKebab,
                                'consultations.tabs.pharmacist-' . $labelKebab,
                                'consultations.tabs.' . $labelSnake,

                                // legacy locations for backward compatibility
                                'consultations.forms.' . $slugForTabInclude,
                                'consultations.forms._' . $slugForTabInclude,
                                'consultations.steps.' . $slugForTabInclude,
                                'consultations.steps._' . $slugForTabInclude,
                                'consultations.forms.' . $labelKebab,
                                'consultations.steps.' . $labelKebab,
                                'consultations.forms.' . $labelSnake,
                                'consultations.steps.' . $labelSnake,
                            ];

                            $firstExisting = collect($candidates)->first(fn($v) => view()->exists((string) $v));
                        @endphp
                        @if ($firstExisting)
                            @include($firstExisting, ['session'=>$session,'order'=>$order,'meta'=>$meta,'selected'=>$selected ?? null,'step'=>$label])
                        @else
                            <div class="text-sm text-gray-400">No content yet for {{ $label }}.</div>
                        @endif

                        @if (app()->environment('local') || request()->has('debug'))
                            <div class="mt-4 text-xs text-gray-500 border-t border-gray-800 pt-2">
                                <div>Debug: tab=<code>{{ $slug }}</code> ({{ $label }}) • type=<code>{{ $typeForForm ?? '' }}</code> • filters: service=<code>{{ $serviceSlugForForm ?? 'null' }}</code>, treatment=<code>{{ $treatmentSlugForForm ?? 'null' }}</code></div>
                                <div>clinicForm: <code>{{ $clinicForm?->id ?? 'none' }}</code>, schema_count: <code>{{ isset($schemaDecoded) && is_array($schemaDecoded) ? count($schemaDecoded) : 0 }}</code></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </x-filament::card>
    <style>
        /* Add spacing below checkboxes inside the consultation form */
        .filament-card input[type="checkbox"] {
            margin-bottom: 1.5rem !important;
            display: inline-block;
        }

        /* For checkboxes with labels, ensure spacing applies to label too */
        .filament-card label:has(input[type="checkbox"]) {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem !important;
        }
    </style>
</x-filament-panels::page>
