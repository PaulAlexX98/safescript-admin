<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('Zoom meetings')
                ->url('https://zoom.us/meeting#/upcoming')
                ->openUrlInNewTab(),
            Actions\CreateAction::make()
                ->mutateFormDataUsing(function (array $data): array {
                    if (! array_key_exists('online_consultation', $data)) {
                        $data['online_consultation'] = false;
                    }

                    if (empty($data['order_reference'] ?? null)) {
                        $data['order_reference'] = \App\Filament\Resources\Appointments\AppointmentResource::generatePcaoRef();
                    }

                    return $data;
                })
                ->after(function ($record): void {
                    if ($record) {
                        \App\Filament\Resources\Appointments\AppointmentResource::ensureZoomStoredForAppointment($record);
                    }
                }),
        ];
    }
}