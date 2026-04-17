<x-filament-panels::page x-data="consultationRunner()" x-cloak>
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
      $consultationBaseUrl = url('/admin/consultations/' . ($session->id ?? $session->getKey()));
      $consultationCompleteUrl = $consultationBaseUrl . '/complete';
      $tabOrderForJs = array_keys($tabs);
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
    <div x-show="saveAllTabsMessage" x-transition.opacity.duration.250ms class="mb-4 px-2 md:px-3">
      <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-300 shadow-sm">
        <span x-text="saveAllTabsMessage"></span>
      </div>
    </div>
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
        @if ($isReorder)
        <div class="p-2 md:p-3">
          <x-filament::button type="button"
            x-on:click="saveAllTabs()"
            x-bind:disabled="isSubmitting"
            color="primary" size="md" class="px-8 py-3 text-lg">
            Save all tabs
          </x-filament::button>
        </div>
        @endif
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
        const order = @json($tabOrderForJs);
        const current = @json($currentTab);
        const idx = order.indexOf(current);
        if (idx > -1 && idx < order.length - 1) {
          const nextSlug = order[idx + 1];
          const base = @json($consultationBaseUrl);
          window.location.replace(base + '/' + nextSlug);
        }
      }
    } catch (e) {}
    try {
      if (sessionStorage.getItem('consult_complete') === '1') {
        sessionStorage.removeItem('consult_complete');
        const completeUrl = @json($consultationCompleteUrl);
        window.location.replace(completeUrl);
      }
    } catch (e) {}

  });
</script>
</x-filament-panels::page>
<script>
  function consultationRunner() {
    return {
      isSubmitting: false,
      saveAllTabsMessage: '',
      submitCurrent(goNext) {
        const area = document.getElementById('consultation-section');
        const form = area ? area.querySelector('form') : document.querySelector('form');
        if (!form) return;
        let next = form.querySelector('[name=__go_next]');
        if (!next) {
          next = document.createElement('input');
          next.type = 'hidden';
          next.name = '__go_next';
          form.appendChild(next);
        }
        next.value = goNext ? '1' : '0';
        if (goNext) {
          try { sessionStorage.setItem('consult_next', '1'); } catch (e) {}
        }
        this.isSubmitting = true;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      },
      goComplete() {
        const area = document.getElementById('consultation-section');
        const form = area ? area.querySelector('form') : document.querySelector('form');
        if (!form) return;
        let next = form.querySelector('[name=__go_next]');
        if (!next) {
          next = document.createElement('input');
          next.type = 'hidden';
          next.name = '__go_next';
          form.appendChild(next);
        }
        next.value = '0';
        try { sessionStorage.setItem('consult_complete', '1'); } catch (e) {}
        this.isSubmitting = true;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
      },
      confirmComplete() {
        const form = document.getElementById('consult-complete-form');
        if (!form) return;
        this.isSubmitting = true;
        form.submit();
      },
      async saveAllTabs() {
        if (this.isSubmitting) return;
        this.isSubmitting = true;
        this.saveAllTabsMessage = '';

        try {
          const parts = window.location.pathname.split('/').filter(Boolean);
          const sessionId = parts.length >= 3 ? parts[2] : null;
          if (!sessionId) {
            console.error('[saveAllTabs] could not resolve session id from URL', window.location.pathname);
            return;
          }

          const base = `${window.location.origin}/admin/consultations/${sessionId}`;
          const notesEl = document.querySelector('#consultation_notes, textarea[name="consultation_notes"], textarea[name="consultation-notes"]');
          const notesHiddenEl = document.querySelector('#consultation_notes_hidden, input[name="consultation_notes"], input[name="consultation-notes"]');
          const notesValue = notesEl
            ? (notesEl.value || '')
            : (notesHiddenEl ? (notesHiddenEl.value || '') : '');

          if (notesHiddenEl && notesEl) {
            notesHiddenEl.value = notesValue;
          }

          const urls = [];

          if (document.querySelector('a[href*="/reorder"]')) {
            urls.push(`${base}/reorder?tab=reorder`);
          }

          urls.push(`${base}/pharmacist-advice?tab=pharmacist_advice`);
          urls.push(`${base}/pharmacist-declaration?tab=pharmacist_declaration`);
          urls.push(`${base}/record-of-supply?tab=record_of_supply`);

          const runSave = (url) => new Promise((resolve) => {
            const iframe = document.createElement('iframe');
            iframe.style.position = 'absolute';
            iframe.style.width = '1px';
            iframe.style.height = '1px';
            iframe.style.opacity = '0';
            iframe.style.pointerEvents = 'none';
            iframe.style.left = '-9999px';
            iframe.setAttribute('aria-hidden', 'true');
            document.body.appendChild(iframe);

            let finished = false;
            const cleanup = () => {
              if (finished) return;
              finished = true;
              setTimeout(() => {
                try { iframe.remove(); } catch (e) {}
                resolve();
              }, 200);
            };

            const failSafe = setTimeout(() => {
              console.warn('[saveAllTabs] timeout waiting for', url);
              cleanup();
            }, 12000);

            iframe.onload = () => {
              try {
                const doc = iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document);
                const form = doc && (
                  doc.querySelector('#consultation-section form') ||
                  doc.querySelector("form[id^='cf_']") ||
                  doc.querySelector("form[action*='consultations/forms/save']") ||
                  doc.querySelector('form')
                );

                console.log('[saveAllTabs] loaded', { url, hasDoc: !!doc, hasForm: !!form });

                if (!doc || !form) {
                  clearTimeout(failSafe);
                  cleanup();
                  return;
                }

                let next = form.querySelector("[name='__go_next']");
                if (!next) {
                  next = doc.createElement('input');
                  next.type = 'hidden';
                  next.name = '__go_next';
                  form.appendChild(next);
                }
                next.value = '0';

                let mark = form.querySelector("[name='__mark_complete']");
                if (!mark) {
                  mark = doc.createElement('input');
                  mark.type = 'hidden';
                  mark.name = '__mark_complete';
                  form.appendChild(mark);
                }
                mark.value = '0';

                iframe.onload = () => {
                  clearTimeout(failSafe);
                  console.log('[saveAllTabs] submitted', { url });
                  cleanup();
                };

                if (form.requestSubmit) {
                  form.requestSubmit();
                } else {
                  form.submit();
                }
              } catch (e) {
                clearTimeout(failSafe);
                console.error('[saveAllTabs] error for', url, e);
                cleanup();
              }
            };

            iframe.src = url;
          });

          for (const url of urls) {
            await runSave(url);
          }

          let notesSaved = false;

          try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
              || document.querySelector('input[name="_token"]')?.value
              || '';

            console.log('[saveAllTabs] consultation notes length', notesValue ? notesValue.length : 0);

            const response = await fetch(`${base}/save-all-tabs`, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
              },
              credentials: 'same-origin',
              body: JSON.stringify({
                consultation_notes: notesValue,
              }),
            });

            let payload = null;
            try {
              payload = await response.json();
            } catch (e) {
              payload = null;
            }

            notesSaved = !!(response.ok && payload && payload.consultation_notes_saved);
            console.log('[saveAllTabs] save-all-tabs response', { status: response.status, payload, notesSaved });
          } catch (e) {
            console.error('[saveAllTabs] consultation notes save failed', e);
          }

          this.saveAllTabsMessage = notesSaved
            ? 'All tabs and consultation notes are saved.'
            : 'Tabs are saved, but consultation notes were not saved.';
          setTimeout(() => {
            if (
              this.saveAllTabsMessage === 'All tabs and consultation notes are saved.' ||
              this.saveAllTabsMessage === 'Tabs are saved, but consultation notes were not saved.'
            ) {
              this.saveAllTabsMessage = '';
            }
          }, 4000);
        } finally {
          this.isSubmitting = false;
        }
      }
    };
  }
</script>