<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use App\Models\Page;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Ensure the Raw HTML textarea is seeded from DB if the state is empty or just a placeholder
        $current = trim((string)($data['content'] ?? ''));
        if ($current === '' || $current === '<p></p>') {
            // `$this->record` is the bound model. Fetch latest content directly from DB
            $fresh = Page::query()->whereKey($this->record->getKey())->value('content');
            if (is_string($fresh) && trim($fresh) !== '') {
                $data['content'] = $fresh;
            }
        }
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Read full Livewire form state (includes non-dehydrated fields like edit_mode, content_rich)
        $state = method_exists($this, 'getForm') && $this->getForm()
            ? $this->getForm()->getState()
            : (property_exists($this, 'form') && $this->form ? $this->form->getState() : []);

        // Normalise rich editor value into a string
        $rich = $state['content_rich'] ?? '';
        if (is_array($rich)) {
            $rich = $rich['html'] ?? $rich['content'] ?? '';
        }
        $rich = is_string($rich) ? $rich : (string) $rich;

        // If the rich editor has meaningful HTML, prefer it for `content`
        $trimmed = trim($rich);
        if ($trimmed !== '' && $trimmed !== '<p></p>' && strlen($trimmed) > 5) {
            $data['content'] = $rich;
        }

        return $data;
    }
}