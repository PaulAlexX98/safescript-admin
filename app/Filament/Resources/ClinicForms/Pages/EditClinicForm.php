<?php

namespace App\Filament\Resources\ClinicForms\Pages;

use Filament\Actions\Action;
use App\Filament\Resources\ClinicForms\ClinicFormResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClinicForm extends EditRecord
{
    protected static string $resource = ClinicFormResource::class;

    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();

        $actions[] = Action::make('raf')
            ->label('Risk Assessment Builder')
            ->visible(fn () => $this->record->slug === 'weight-management-service')
            ->url(static::getResource()::getUrl('raf-builder', ['record' => $this->record]));

        return $actions;
    }
}
