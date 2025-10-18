<x-filament::page>
  <div class="space-y-6">
    <div class="text-sm text-gray-400">
      Record ID {{ $record->id }} service {{ $record->service_slug }}
    </div>

    {{-- Render the Filament form --}}
    {{ $this->form }}

    {{-- Header actions already show Save but this is an optional inline button --}}
    <div class="mt-4">
      <x-filament::button color="success" wire:click="save">Save</x-filament::button>
    </div>
  </div>
</x-filament::page>