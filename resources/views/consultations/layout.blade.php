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
    showConfirm:false,
    openCompleteConfirm(){ this.showConfirm = true; },
    cancelComplete(){ this.showConfirm = false; },
    confirmComplete(){
      const area = document.getElementById('consultation-section');
      const form = area ? area.querySelector('form') : document.querySelector('form');
      if(!form){ this.showConfirm=false; return; }
      let mark = form.querySelector('[name=__mark_complete]');
      if(!mark){ mark = document.createElement('input'); mark.type='hidden'; mark.name='__mark_complete'; form.appendChild(mark); }
      mark.value = '1';
      let next = form.querySelector('[name=__go_next]');
      if(!next){ next = document.createElement('input'); next.type='hidden'; next.name='__go_next'; form.appendChild(next); }
      next.value = '0';
      this.showConfirm=false;
      this.isSubmitting = true;
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }
  }">
  @php
    $tabs = [
      'risk-assessment' => 'Risk Assessment',
      'pharmacist-advice' => 'Pharmacist Advice',
      'pharmacist-declaration' => 'Pharmacist Declaration',
      'record-of-supply' => 'Record of Supply',
    ];

    // Only show the Reorder tab when this consultation is a reorder
    $ordType = data_get($session, 'order.type')
      ?: data_get($session, 'order.meta.type')
      ?: data_get($session, 'type')
      ?: data_get($session, 'meta.type');

    $hasReorderTemplate = (bool) data_get($session, 'templates.reorder');
    $stepsHasReorder    = in_array('reorder', (array) ($session->steps ?? []), true);

    $isReorder = ($ordType === 'reorder') || $hasReorderTemplate || $stepsHasReorder;

    if ($isReorder && !array_key_exists('reorder', $tabs)) {
        $tabs['reorder'] = 'Reorder';
    }

    $requestedTab = request()->segment(4) ?: request()->query('tab', 'risk-assessment');
    $currentTab = str_replace('_', '-', strtolower((string) $requestedTab));

    // If the requested tab isn't in the visible tab list, only fall back
    // to the first tab when there is no matching view. This enables routes
    // like /reorder to render their dedicated blade.
    if (! array_key_exists($currentTab, $tabs)) {
        $vc = [
            'consultations.tabs.' . $currentTab,
            'consultations.' . $currentTab,
            'consultations.' . str_replace('pharmacist-', '', $currentTab),
        ];
        $hasView = collect($vc)->first(fn ($v) => view()->exists((string) $v));
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

    <div class="mt-6">
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
    <div class="flex flex-wrap items-center justify-start -m-2 md:-m-3">
      <div class="p-2 md:p-3">
        <x-filament::button type="button"
          x-on:click="submitCurrent(false)"
          x-bind:disabled="isSubmitting"
          color="warning" size="md" class="px-8 py-3 text-lg">
          Save
        </x-filament::button>
      </div>
      <div class="p-2 md:p-3">
        <x-filament::button type="button"
          x-on:click="submitCurrent(true)"
          x-bind:disabled="isSubmitting"
          color="gray" size="md" class="px-8 py-3 text-lg">
          Save and Next
        </x-filament::button>
      </div>
      @if ($currentTab === 'record-of-supply')
        <div class="p-2 md:p-3">
          <x-filament::button type="button"
            x-on:click="openCompleteConfirm()"
            color="success" size="md" class="px-8 py-3 text-lg">
            Complete Consultation
          </x-filament::button>
        </div>
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
  });
</script>
<div x-cloak x-show="showConfirm" class="fixed inset-0 z-[70] flex items-center justify-center">
  <div class="absolute inset-0 bg-black/60" x-on:click="cancelComplete()"></div>
  <div class="relative z-[71] w-full max-w-md rounded-xl p-6" style="background:#0b0b0b;box-shadow:none;border:0;">
    <h3 class="text-lg font-semibold text-white mb-2">Complete consultation?</h3>
    <p class="text-sm text-gray-300 mb-6">This will mark the consultation as complete after saving the current tab.</p>
    <div class="flex justify-end gap-3">
      <x-filament::button type="button" color="gray" size="sm" x-on:click="cancelComplete()">Cancel</x-filament::button>
      <x-filament::button
        type="button"
        color="danger"
        size="sm"
        wire:click="completeConsultation"
        x-on:click="cancelComplete()"
      >
        Yes, complete
      </x-filament::button>
    </div>
  </div>
</div>
</x-filament-panels::page>