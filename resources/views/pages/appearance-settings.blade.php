@php /** @var \App\Filament\Pages\AppearanceSettings $this */ @endphp
<x-filament-panels::page>
    {{ $this->form }}
    <x-filament-actions::actions :actions="[
        \Filament\Actions\Action::make('save')->label('Save')->submit('save')->color('primary'),
    ]" alignment="left" />
</x-filament-panels::page>