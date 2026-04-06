<x-filament-panels::page x-data="{ 
    isSubmitting:false,
    submitCurrent(goNext){
      const area = document.getElementById('consultation-section');
      const form = area ? area.querySelector('form') : document.querySelector('form');
      if(!form) return;
      let next = form.querySelector('[name=__go_next]');
      if(!next){ next = document.createElement('input'); next.type='hidden'; next.name='__go_next'; form.appendChild(next); }
      next.value = goNext ? '1' : '0';
      if (goNext) { try { sessionStorage.setItem('consult_next','1'); } catch(e){} }
      this.isSubmitting = true;
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    },
    goComplete(){
      const area = document.getElementById('consultation-section');
      const form = area ? area.querySelector('form') : document.querySelector('form');
      if(!form) return;
      let next = form.querySelector('[name=__go_next]');
      if(!next){ next = document.createElement('input'); next.type='hidden'; next.name='__go_next'; form.appendChild(next); }
      next.value = '0';
      try { sessionStorage.setItem('consult_complete','1'); } catch(e){}
      this.isSubmitting = true;
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    },
    confirmComplete(){
      // Submit only the explicit complete form
      const form = document.getElementById('consult-complete-form');
      if (!form) return;
      this.isSubmitting = true;
      form.submit();
    }
  }">
  @php
      // Base tab order
      $tabs = [
          'risk-assessment'        => 'Risk Assessment',
          'pharmacist-advice'      => 'Pharmacist Advice',
          'pharmacist-declaration' => 'Pharmacist Declaration',
          'record-of-supply'       => 'Record of Supply',
      ];

      // Figure out if this session is a reorder flow
      $consultType        = data_get($session, 'meta.consultation.type') ?: data_get($session, 'meta.consultation.mode');
      $hasReorderTemplate = (bool) data_get($session, 'templates.reorder');
      $stepsHasReorder    = in_array('reorder', (array) ($session->steps ?? []), true);

      $isReorder = ($consultType === 'reorder') || $hasReorderTemplate || $stepsHasReorder;

      // For reorder flows: hide RAF completely and make Reorder the first tab
      if ($isReorder) {
          unset($tabs['risk-assessment']);
          $tabs = array_merge(['reorder' => 'Reorder'], $tabs);
      }

      // Decide which tab is active: route segment -> ?tab= -> flow default
      $requestedTab = request()->segment(4)
          ?: request()->query('tab')
          ?: ($isReorder ? 'reorder' : 'risk-assessment');

      $currentTab = str_replace('_', '-', strtolower((string) $requestedTab));
      $lastTab = array_key_last($tabs);
      $isLastTab = ($currentTab === $lastTab);

      $isCompletePage = ($currentTab === 'complete') || request()->is('admin/consultations/*/complete');

      $helperServiceSlug = (string) (
          $session->service_slug
          ?? data_get($session, 'meta.service_slug')
          ?? data_get($session, 'meta.service.slug')
          ?? data_get($session, 'meta.service')
          ?? ''
      );
      $helperServiceSlug = str_replace(' ', '-', strtolower(trim($helperServiceSlug)));

      // If the requested tab isn't in the visible list and we don't have a view for it, fall back to the first tab
      if (! array_key_exists($currentTab, $tabs)) {
          $variants = [
              'consultations.tabs.' . $currentTab,
              'consultations.' . $currentTab,
              'consultations.' . str_replace('pharmacist-', '', $currentTab),
          ];
          $hasView = collect($variants)->first(fn ($v) => view()->exists((string) $v));
          if (! $hasView) {
              $currentTab = array_key_first($tabs);
          }
      }
  @endphp
  <style>
    /* Remove any white borders or rings from Filament sections on this page */
    #consultation-section .fi-section {
      background: transparent !important;
      box-shadow: none !important;
      border: 0 !important;
    }
    #consultation-section .fi-section > div {
      background: transparent !important;
      box-shadow: none !important;
      border: 0 !important;
    }
  </style>

  <style>[x-cloak]{display:none !important;}</style>

  <style>
    /* Force dark background to remove bottom white bar */
    html, body, #app, .fi-body, .fi-main, .fi-page, .fi-content {
      background: #0b0b0b !important;
      color-scheme: dark;
      min-height: 100vh !important;
    }

    /* Remove body margin and extra scroll gaps */
    html, body {
      margin: 0 !important;
      padding: 0 !important;
      overflow-x: hidden;
    }

    /* Ensure Filament main area extends full height */
    .fi-main {
      min-height: 100vh !important;
      padding-bottom: 0 !important;
    }

    /* Neutralize any white utility backgrounds */
    .bg-white, .fi-bg, .fi-bg-white {
      background: #0b0b0b !important;
    }

    /* Handle sticky footer / bottom gap */
    .fi-footer, footer {
      background: transparent !important;
      border-top: 0 !important;
    }
  </style>

  {{-- Tabs --}}
  <div class="max-w-5xl mx-auto my-12 py-4">
    <div class="flex flex-wrap justify-center -m-2 md:-m-3">
      @foreach ($tabs as $slug => $label)
        @php $active = $currentTab === $slug; @endphp
        <div class="p-2 md:p-3">
          <x-filament::button
            :tag="'a'"
            href="{{ url('/admin/consultations/' . ($session->id ?? $session->getKey()) . '/' . $slug) }}"
            :color="$active ? 'warning' : 'gray'"
            size="md"
            class="px-8 py-3 text-lg rounded-full">
            {{ $label }}
          </x-filament::button>
        </div>
      @endforeach
    </div>
  </div>

  {{-- Section Content --}}
  <x-filament::section id="consultation-section" class="max-w-5xl mx-auto px-6 bg-transparent shadow-none ring-0 border-0">
    @php
      $includeSlug = $currentTab;
      $viewCandidates = [
        'consultations.tabs.' . $includeSlug,
        'consultations.' . $includeSlug,
        'consultations.' . str_replace('pharmacist-', '', $includeSlug),
      ];
      $activeView = collect($viewCandidates)->first(fn($v) => view()->exists((string) $v));
    @endphp

    <div class="mt-8">
      @if ($activeView)
        @if (in_array($currentTab, ['risk-assessment', 'reorder'], true))
          <div id="wm-bmi-helper-layout" class="mb-8 rounded-xl border border-white/10 bg-white/5 p-4" style="display:none;">
            <div class="flex flex-wrap items-center gap-3">
              <x-filament::button type="button" color="gray" size="sm" id="wm-open-bmi-layout">
                Calculate BMI
              </x-filament::button>
              <x-filament::button type="button" color="gray" size="sm" id="wm-open-gp-layout">
                Find GP
              </x-filament::button>
            </div>
            <p class="mt-3 text-sm text-gray-300">Enter height in cm and weight in kg for BMI to be added automatically.</p>
            <div id="wm-gp-helper-layout" class="hidden w-full rounded-xl border border-white/10 bg-white/5 p-4 mt-4">
              <div class="w-full space-y-3">
                <input
                  type="text"
                  id="wm-gp-query-layout"
                  class="block w-full rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm text-white"
                  placeholder="Search GP practice name, postcode or code"
                  autocomplete="off"
                >
                <div class="flex items-center gap-3">
                  <button
                    type="button"
                    id="wm-run-gp-layout"
                    class="inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white hover:bg-white/10"
                  >
                    Search GP
                  </button>
                  <div id="wm-gp-status-layout" class="text-sm text-gray-300"></div>
                </div>
                <div id="wm-gp-results-layout" class="block w-full space-y-2"></div>
              </div>
            </div>
          </div>
        @endif
        @include($activeView, [
          'session' => $session,
          'serviceSlugForForm' => ($session->service_slug ?? null),
          'treatmentSlugForForm' => ($session->treatment_slug ?? null),
        ])
      @else
        <div class="text-sm text-gray-400">Select a tab to begin consultation.</div>
      @endif
    </div>
  </x-filament::section>

  <div class="max-w-5xl mx-auto mt-14 mb-10 w-full">
    <div class="flex flex-wrap items-center justify-start -m-2 md:-m-3">
      @if ($isCompletePage)
        <div class="p-2 md:p-3">
          <x-filament::button type="button"
            x-on:click="confirmComplete()"
            x-bind:disabled="isSubmitting"
            color="success" size="md" class="px-8 py-3 text-lg">
            Confirm and complete
          </x-filament::button>
        </div>
        <div class="p-2 md:p-3">
          <x-filament::button
            :tag="'a'"
            href="{{ url('/admin/consultations/' . ($session->id ?? $session->getKey()) . '/risk-assessment') }}"
            color="gray" size="md" class="px-8 py-3 text-lg">
            Back to consultation
          </x-filament::button>
        </div>
      @else
        <div class="p-2 md:p-3">
          <x-filament::button type="button"
            x-on:click="submitCurrent(false)"
            x-bind:disabled="isSubmitting"
            color="warning" size="md" class="px-8 py-3 text-lg">
            Save
          </x-filament::button>
        </div>
        @if ($isLastTab)
          <div class="p-2 md:p-3">
            <x-filament::button type="button"
              x-on:click="goComplete()"
              x-bind:disabled="isSubmitting"
              color="danger" size="md" class="px-8 py-3 text-lg">
              Save and Complete Consultation
            </x-filament::button>
          </div>
        @else
          <div class="p-2 md:p-3">
            <x-filament::button type="button"
              x-on:click="submitCurrent(true)"
              x-bind:disabled="isSubmitting"
              color="gray" size="md" class="px-8 py-3 text-lg">
              Save and Next
            </x-filament::button>
          </div>
        @endif
      @endif
    </div>
  </div>
<script>
  document.addEventListener('DOMContentLoaded', function(){
      // Ensure a default __go_next=0 exists so Enter triggers Save
      try {
        const area = document.getElementById('consultation-section');
        const form = area ? area.querySelector('form') : document.querySelector('form');
        if (form) {
          let next = form.querySelector('[name="__go_next"]');
          if (!next) {
            next = document.createElement('input');
            next.type = 'hidden';
            next.name = '__go_next';
            next.value = '0';
            form.appendChild(next);
          } else if (!next.value) {
            next.value = '0';
          }
        }
      } catch(e){}
    try {
      if (sessionStorage.getItem('consult_next') === '1') {
        sessionStorage.removeItem('consult_next');
        const order = @json(array_keys($tabs));
        const current = @json($currentTab);
        const idx = order.indexOf(current);
        if (idx > -1 && idx < order.length - 1) {
          const nextSlug = order[idx + 1];
          const base = @json(url('/admin/consultations/' . ($session->id ?? $session->getKey())));
          window.location.replace(base + '/' + nextSlug);
        }
      }
    } catch (e) {}
    try {
      if (sessionStorage.getItem('consult_complete') === '1') {
        sessionStorage.removeItem('consult_complete');
        const completeUrl = @json(url('/admin/consultations/' . ($session->id ?? $session->getKey()) . '/complete'));
        window.location.replace(completeUrl);
      }
    } catch (e) {}

    try {
      const bmiBtn = document.getElementById('wm-open-bmi-layout');
      const gpBtn = document.getElementById('wm-open-gp-layout');
      const gpRunBtn = document.getElementById('wm-run-gp-layout');
      const gpQueryInput = document.getElementById('wm-gp-query-layout');
      const gpStatus = document.getElementById('wm-gp-status-layout');
      const gpResults = document.getElementById('wm-gp-results-layout');
      const gpHelperPanel = document.getElementById('wm-gp-helper-layout');
      const bmiHelperWrap = document.getElementById('wm-bmi-helper-layout');

      function slugValue(x) {
        if (x === true) return 'true';
        if (x === false) return 'false';
        x = (x == null ? '' : String(x)).toLowerCase().trim();
        return x.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
      }

      function getAliases() {
        return window.__cfAliases || {};
      }

      function canonicalName(name) {
        const aliases = getAliases();
        const key = slugValue(name);
        return aliases[key] || aliases[name] || name;
      }

      function getActiveConsultForm() {
        return document.getElementById('cf_risk-assessment') || document.getElementById('cf_reorder');
      }

      function shouldShowWmHelper(form) {
        if (!form) return false;
        return !!(
          getFirstExisting(form, ['bmi']) ||
          getFirstExisting(form, ['height_cm','heightcm','height']) ||
          getFirstExisting(form, ['weight_kg','weightkg','weight']) ||
          getFirstExisting(form, ['height_ft','heightft','height_feet','feet','ft']) ||
          getFirstExisting(form, ['height_in','heightin','height_inches','inches','inch']) ||
          getFirstExisting(form, ['weight_st','weightst','weight_stone','stone','st']) ||
          getFirstExisting(form, ['weight_lb','weightlb','weight_lbs','pounds','lbs','lb']) ||
          getFirstExisting(form, ['gp']) ||
          getFirstExisting(form, ['gp_email','gp-email'])
        );
      }

      function getFirstExisting(form, names) {
        if (!form) return null;
        for (let i = 0; i < names.length; i++) {
          const n = canonicalName(names[i]);
          const el = form.querySelector('[name="' + CSS.escape(n) + '"]') || form.querySelector('#' + CSS.escape(n));
          if (el) return el;
        }
        return null;
      }

      function parseNum(v) {
        if (v == null) return null;
        const s = String(v).trim().replace(/,/g, '.');
        if (!s) return null;
        const n = parseFloat(s);
        return Number.isFinite(n) ? n : null;
      }

      function calcBmiMetric(heightCm, weightKg) {
        const h = parseNum(heightCm);
        const w = parseNum(weightKg);
        if (!h || !w || h <= 0 || w <= 0) return null;
        const hm = h / 100;
        if (!hm) return null;
        return w / (hm * hm);
      }

      function calcBmiImperial(feet, inches, stone, pounds) {
        const ft = parseNum(feet) || 0;
        const inch = parseNum(inches) || 0;
        const st = parseNum(stone) || 0;
        const lb = parseNum(pounds) || 0;
        const totalInches = (ft * 12) + inch;
        const totalPounds = (st * 14) + lb;
        if (!totalInches || !totalPounds || totalInches <= 0 || totalPounds <= 0) return null;
        return (totalPounds / (totalInches * totalInches)) * 703;
      }

      function bmiBand(bmi) {
        if (!Number.isFinite(bmi)) return '';
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25) return 'Healthy weight';
        if (bmi < 30) return 'Overweight';
        return 'Obesity';
      }

      function parseCsvLine(line) {
        const out = [];
        let cur = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
          const ch = line[i];
          if (ch === '"') {
            if (inQuotes && line[i + 1] === '"') {
              cur += '"';
              i++;
            } else {
              inQuotes = !inQuotes;
            }
          } else if (ch === ',' && !inQuotes) {
            out.push(cur);
            cur = '';
          } else {
            cur += ch;
          }
        }
        out.push(cur);
        return out;
      }

      function parseCsv(text) {
        if (!text) return [];
        const lines = String(text).split(/\r?\n/).filter(Boolean);
        if (!lines.length) return [];
        const headers = parseCsvLine(lines.shift()).map((h) => String(h || '').trim());
        return lines.map((line) => {
          const cols = parseCsvLine(line);
          const row = {};
          headers.forEach((h, idx) => {
            row[h] = cols[idx] != null ? String(cols[idx]).trim() : '';
          });
          return row;
        });
      }

      function formatPractice(item) {
        if (!item) return { title: '', subtitle: '' };
        const name = item.name || item.practice || item.organisation || item.practice_name || item['Practice Name'] || item['Organisation Name'] || item['Name'] || '';
        const code = item.code || item.practice_code || item['Organisation Code'] || item['Code'] || '';
        const email = item.email || item.practice_email || item['Email Address'] || item['Email'] || '';
        const address = [
          item.address,
          item.address1 || item['Address Line 1'],
          item.address2 || item['Address Line 2'],
          item.city || item.town || item['Post Town'],
          item.postcode || item['Postcode'],
        ].filter(Boolean).join(', ');
        const title = [name, code ? '(' + code + ')' : ''].filter(Boolean).join(' ');
        const subtitle = [address, email].filter(Boolean).join(' • ');
        return { title: title || name || code || 'Practice', subtitle: subtitle };
      }

      async function searchEpracurLocal(q) {
        return [];
      }

      const riskForm = getActiveConsultForm();
      if (bmiHelperWrap && shouldShowWmHelper(riskForm)) {
        bmiHelperWrap.style.display = '';
      }

      function applyGpPractice(item) {
        const form = getActiveConsultForm();
        if (!form) return;
        const gpInput = getFirstExisting(form, ['gp']);
        const gpEmailInput = getFirstExisting(form, ['gp_email','gp-email']);
        const fp = formatPractice(item);
        if (gpInput) {
          gpInput.value = [fp.title || item.name || '', fp.subtitle || ''].filter(Boolean).join(' - ');
          gpInput.dispatchEvent(new Event('input', { bubbles: true }));
          gpInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (gpEmailInput) {
          gpEmailInput.value = item.email || '';
          gpEmailInput.dispatchEvent(new Event('input', { bubbles: true }));
          gpEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (gpStatus) gpStatus.textContent = '';
        if (gpResults) gpResults.innerHTML = '';
      }

      function renderGpResults(items) {
        if (!gpResults) return;
        gpResults.innerHTML = '';
        if (!items || !items.length) {
          if (gpStatus) gpStatus.textContent = 'No GP practices found.';
          return;
        }
        if (gpStatus) gpStatus.textContent = '';
        items.forEach((item) => {
          const fp = formatPractice(item);
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'block w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-sm text-white hover:bg-white/10';
          btn.innerHTML = '<strong class="block text-sm">' + (fp.title || 'Practice') + '</strong><span class="block text-xs text-gray-400 mt-1">' + (fp.subtitle || 'Select this practice') + '</span>';
          btn.addEventListener('click', function () { applyGpPractice(item); });
          gpResults.appendChild(btn);
        });
      }

      async function runGpSearch() {
        const q = String(gpQueryInput ? gpQueryInput.value || '' : '').trim();
        if (!q) {
          if (gpStatus) gpStatus.textContent = 'Enter a search term first.';
          if (gpResults) gpResults.innerHTML = '';
          return;
        }
        if (gpStatus) gpStatus.textContent = 'Searching…';
        if (gpResults) gpResults.innerHTML = '';
        if (gpRunBtn) gpRunBtn.disabled = true;
        try {
          let apiResults = [];
          try {
            const res = await fetch('/api/gp-search?q=' + encodeURIComponent(q), { credentials: 'same-origin' });
            if (res.ok) {
              const json = await res.json();
              apiResults = Array.isArray(json)
                ? json
                : (Array.isArray(json.items)
                    ? json.items
                    : (Array.isArray(json.data)
                        ? json.data
                        : (Array.isArray(json.results) ? json.results : [])));
            }
          } catch (e) {}
          renderGpResults((apiResults || []).slice(0, 8));
        } finally {
          if (gpRunBtn) gpRunBtn.disabled = false;
        }
      }

      if (gpBtn) {
        gpBtn.addEventListener('click', function () {
          if (gpHelperPanel) gpHelperPanel.classList.toggle('hidden');
          if (gpHelperPanel && !gpHelperPanel.classList.contains('hidden') && gpQueryInput) {
            gpQueryInput.focus();
          }
        });
      }

      if (gpRunBtn) {
        gpRunBtn.addEventListener('click', function () {
          runGpSearch();
        });
      }

      if (gpQueryInput) {
        let gpSearchTimer = null;

        gpQueryInput.addEventListener('input', function () {
          const q = String(gpQueryInput.value || '').trim();
          if (gpSearchTimer) {
            clearTimeout(gpSearchTimer);
          }
          if (q.length < 2) {
            if (gpStatus) gpStatus.textContent = '';
            if (gpResults) gpResults.innerHTML = '';
            return;
          }
          gpSearchTimer = window.setTimeout(function () {
            runGpSearch();
          }, 250);
        });

        gpQueryInput.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            runGpSearch();
          }
        });
      }

      if (bmiBtn) {
        bmiBtn.addEventListener('click', function () {
          const form = getActiveConsultForm();
          if (!form) return;

          const bmiInput = getFirstExisting(form, ['bmi']);
          if (!bmiInput) {
            return;
          }

          const heightCm = getFirstExisting(form, ['height_cm','heightcm','height']);
          const weightKg = getFirstExisting(form, ['weight_kg','weightkg','weight']);
          const heightFt = getFirstExisting(form, ['height_ft','heightft','height_feet','feet','ft']);
          const heightIn = getFirstExisting(form, ['height_in','heightin','height_inches','inches','inch']);
          const weightSt = getFirstExisting(form, ['weight_st','weightst','weight_stone','stone','st']);
          const weightLb = getFirstExisting(form, ['weight_lb','weightlb','weight_lbs','pounds','lbs','lb']);

          let bmi = null;
          if (heightCm && weightKg) {
            bmi = calcBmiMetric(heightCm.value, weightKg.value);
          }
          if (!Number.isFinite(bmi) && (heightFt || heightIn || weightSt || weightLb)) {
            bmi = calcBmiImperial(
              heightFt ? heightFt.value : null,
              heightIn ? heightIn.value : null,
              weightSt ? weightSt.value : null,
              weightLb ? weightLb.value : null
            );
          }

          if (!Number.isFinite(bmi)) {
            return;
          }

          const rounded = (Math.round(bmi * 10) / 10).toFixed(1);
          bmiInput.value = rounded;
          bmiInput.dispatchEvent(new Event('input', { bubbles: true }));
          bmiInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }
    } catch (e) {}
  });
</script>
</x-filament-panels::page>