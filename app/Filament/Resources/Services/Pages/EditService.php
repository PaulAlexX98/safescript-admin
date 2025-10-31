<?php

namespace App\Filament\Resources\Services\Pages;

use Filament\Actions\Action;
use App\Filament\Resources\Services\ServiceResource;
use Filament\Resources\Pages\EditRecord;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        // “Back to Services” style button + “View Booking Page”
        $actions[] = Action::make('view_booking')
            ->label('View Booking Page')
            ->url(fn () => $this->record?->slug ? url("/private-services/{$this->record->slug}") : '#')
            ->visible(fn () => filled($this->record?->slug))
            ->color('gray');

        return $actions;
    }

    protected function getFooterActions(): array
    {
        return [
            Action::make('save_footer')
                ->label('Save changes')
                ->color('primary')
                ->icon('heroicon-o-check')
                ->action('save'),
        ];
    }
}
