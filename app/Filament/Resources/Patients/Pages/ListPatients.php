<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Filament\Resources\Pages\ListRecords;

class ListPatients extends ListRecords
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('downloadCsv')
                ->label('Download CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): StreamedResponse {
                    $fileName = 'patients-' . now()->format('Y-m-d-His') . '.csv';

                    return response()->streamDownload(function (): void {
                        $handle = fopen('php://output', 'w');

                        fputcsv($handle, [
                            'Internal ID',
                            'First Name',
                            'Last Name',
                            'DOB',
                            'Gender',
                            'Email',
                            'Phone',
                            'Home Address 1',
                            'Home Address 2',
                            'Home City',
                            'Home Postcode',
                            'Home Country',
                            'Shipping Address 1',
                            'Shipping Address 2',
                            'Shipping City',
                            'Shipping Postcode',
                            'Shipping Country',
                            'Created At',
                            'Updated At',
                        ]);

                        Patient::query()
                            ->with('user')
                            ->orderByDesc('id')
                            ->chunk(500, function ($patients) use ($handle): void {
                                foreach ($patients as $patient) {
                                    $user = $patient->user;

                                    $dob = $user?->dob ?? $patient->dob ?? null;
                                    if ($dob) {
                                        try {
                                            $dob = $dob instanceof Carbon
                                                ? $dob->format('d-m-Y')
                                                : Carbon::parse($dob)->format('d-m-Y');
                                        } catch (\Throwable $e) {
                                            $dob = (string) $dob;
                                        }
                                    }

                                    fputcsv($handle, [
                                        $patient->internal_id,
                                        $patient->first_name,
                                        $patient->last_name,
                                        $dob,
                                        $patient->gender,
                                        $patient->email,
                                        $patient->phone,
                                        $user?->address1 ?? $user?->address_1 ?? $user?->address_line1 ?? $patient->address1 ?? $patient->address_1 ?? $patient->address_line1,
                                        $user?->address2 ?? $user?->address_2 ?? $user?->address_line2 ?? $patient->address2 ?? $patient->address_2 ?? $patient->address_line2,
                                        $user?->city ?? $user?->town ?? $user?->locality ?? $patient->city ?? $patient->town,
                                        $user?->postcode ?? $user?->post_code ?? $user?->postal_code ?? $user?->zip ?? $user?->zip_code ?? $patient->postcode ?? $patient->post_code ?? $patient->postal_code,
                                        $user?->country ?? $user?->country_name ?? $patient->country,
                                        $user?->shipping_address1 ?? $user?->shipping_address_1 ?? $user?->shipping_line1,
                                        $user?->shipping_address2 ?? $user?->shipping_address_2 ?? $user?->shipping_line2,
                                        $user?->shipping_city ?? $user?->shipping_town,
                                        $user?->shipping_postcode ?? $user?->shipping_post_code ?? $user?->shipping_postal_code ?? $user?->shipping_zip ?? $user?->shipping_zip_code,
                                        $user?->shipping_country,
                                        optional($patient->created_at)?->format('d-m-Y H:i:s'),
                                        optional($patient->updated_at)?->format('d-m-Y H:i:s'),
                                    ]);
                                }
                            });

                        fclose($handle);
                    }, $fileName, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                    ]);
                }),
        ];
    }
}
