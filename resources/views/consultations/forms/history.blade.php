{{-- PARTIAL: Submitted forms as cards with View / Edit / History buttons --}}
{{-- Expects: $forms (Collection of ConsultationFormResponse), $sessionId (int), $orderId (int|nil) --}}

<div x-data="{ modalOpen: false, modalTitle: '', modalUrl: '' }">
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    @forelse($forms as $form)
      @php
        $id       = $form->id;
        $rawType  = (string) ($form->form_type ?? '');
        $titleMap = [
          'risk-assessment'        => 'Risk Assessment',
          'pharmacist-declaration' => 'Pharmacist Declaration',
          'patient-declaration'    => 'Patient Declaration',
          'pharmacist-advice'    => 'Pharmacist Advice',
          'record-of-supply'       => 'Record of Supply',
        ];
        $title    = $titleMap[$rawType] ?? ($form->title ?? ucfirst(str_replace('-', ' ', $rawType)));
        $item     = data_get($form->meta, 'selectedProduct.name')
                     ?? data_get($form->meta, 'product_name')
                     ?? '—';
        $created  = optional($form->created_at)->format('d-m-Y H:i');

        // Build URLs (routes exist as: consultations.forms.view|edit|history)
        $viewUrl    = isset($sessionId) ? route('consultations.forms.view',    ['session' => $sessionId, 'form' => $id]) : null;
        $editUrl    = isset($sessionId) ? route('consultations.forms.edit',    ['session' => $sessionId, 'form' => $id]) : null;
        $historyUrl = isset($sessionId) ? route('consultations.forms.history', ['session' => $sessionId, 'form' => $id]) : null;
      @endphp

      <div class="border border-white/10 rounded-xl p-4 bg-gray-900/40 shadow-sm">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-white">{{ $title }}</div>
          <div class="text-xs text-gray-400">{{ $created }}</div>
        </div>

        <div class="text-sm text-gray-300 mb-4">{{ $item }}</div>

        <div class="flex items-center gap-2">
          @if($viewUrl)
            <button type="button"
              class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium border border-white/10 bg-white/10 hover:bg-white/20 text-white"
              @click="modalTitle='View — {{ $title }}'; modalUrl='{{ $viewUrl }}'; modalOpen=true;">
              View
            </button>
          @endif
          @if($editUrl)
            <button type="button"
              class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium border border-white/10 bg-white/10 hover:bg-white/20 text-white"
              @click="modalTitle='Edit — {{ $title }}'; modalUrl='{{ $editUrl }}'; modalOpen=true;">
              Edit
            </button>
          @endif
          @if($historyUrl)
            <button type="button"
              class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium border border-white/10 bg-white/10 hover:bg-white/20 text-white"
              @click="modalTitle='History — {{ $title }}'; modalUrl='{{ $historyUrl }}'; modalOpen=true;">
              History
            </button>
          @endif
        </div>
      </div>
    @empty
      <div class="text-sm text-gray-400">No submissions found.</div>
    @endforelse
  </div>

  {{-- Reusable modal (iframe) --}}
  <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/60" @click="modalOpen=false"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
      <div class="bg-gray-900 border border-white/10 rounded-xl shadow-xl w-full max-w-5xl overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 border-b border-white/10">
          <h3 class="font-semibold text-white" x-text="modalTitle"></h3>
          <button type="button" class="text-gray-300 hover:text-white" @click="modalOpen=false" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-5 h-5">
              <path fill-rule="evenodd" d="M10 8.586l4.95-4.95a1 1 0 111.414 1.415L11.414 10l4.95 4.95a1 1 0 01-1.414 1.414L10 11.414l-4.95 4.95a1 1 0 01-1.414-1.414L8.586 10l-4.95-4.95A1 1 0 115.05 3.636L10 8.586z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
        <div class="aspect-[16/10]">
          <iframe x-show="modalUrl" :src="modalUrl" class="w-full h-full" frameborder="0"></iframe>
        </div>
      </div>
    </div>
  </div>
</div>