<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Default new appointments to waiting when the column exists
        if (Schema::hasColumn('appointments', 'status') && empty($data['status'])) {
            $data['status'] = 'waiting';
        }

        // Generate a stable reference when admin creates without one
        $ref = '';
        if (isset($data['order_reference']) && is_string($data['order_reference'])) {
            $ref = trim($data['order_reference']);
        }
        if ($ref === '' && isset($data['reference']) && is_string($data['reference'])) {
            $ref = trim($data['reference']);
        }
        if ($ref === '') {
            $ref = method_exists(AppointmentResource::class, 'generatePcaoRef')
                ? AppointmentResource::generatePcaoRef()
                : ('PCAO-' . strtoupper(bin2hex(random_bytes(4))));
        }

        if (Schema::hasColumn('appointments', 'reference') && (empty($data['reference']) || ! is_string($data['reference']))) {
            $data['reference'] = $ref;
        }

        if (Schema::hasColumn('appointments', 'order_reference') && (empty($data['order_reference']) || ! is_string($data['order_reference']))) {
            $data['order_reference'] = $ref;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $state = method_exists($this->form, 'getRawState')
            ? $this->form->getRawState()
            : $this->form->getState();

        $isOnline = (bool) (data_get($state, 'online_consultation') ?? false);

        \Log::info('appointment.created.online_toggle', [
            'appointment' => $this->record?->id ?? null,
            'online_consultation' => $isOnline,
            'state_has_key' => array_key_exists('online_consultation', is_array($state) ? $state : []),
        ]);

        $record = $this->record;
        if (! $record) return;

        // Prefer form state / raw DB value to avoid accessors
        $email = '';
        try {
            $email = data_get($state, 'email')
                ?? data_get($state, 'patient.email')
                ?? data_get($state, 'patient_email')
                ?? data_get($state, 'customer.email')
                ?? '';
            $email = is_string($email) ? trim((string) $email) : '';
        } catch (\Throwable $e) {
            $email = '';
        }

        if ($email === '') {
            $rawEmail = $record->getRawOriginal('email');
            $email = is_string($rawEmail) ? trim((string) $rawEmail) : '';
        }

        $order = null;
        $pending = null;

        try {
            if (Schema::hasColumn('appointments', 'order_id') && ! empty($record->order_id)) {
                $order = Order::find($record->order_id);
            }
        } catch (\Throwable $e) {}

        try {
            if (! $order) {
                $ref = $record->getRawOriginal('order_reference');
                $ref = is_string($ref) ? trim((string) $ref) : '';
                if ($ref === '') {
                    $ref2 = $record->getRawOriginal('reference');
                    $ref = is_string($ref2) ? trim((string) $ref2) : '';
                }

                if ($ref !== '') {
                    $order = Order::query()->where('reference', $ref)->orderByDesc('id')->first();
                }
            }
        } catch (\Throwable $e) {}

        try {
            if (! $order && method_exists(AppointmentResource::class, 'findRelatedOrder')) {
                $order = AppointmentResource::findRelatedOrder($record);
            }
        } catch (\Throwable $e) {}

        // If email was not entered on the appointment, try to derive it from the related order
        if ($email === '' && $order) {
            try {
                if ($order) {
                    // Prefer structured meta where available
                    $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                    $email = Arr::get($meta, 'patient.email')
                        ?? Arr::get($meta, 'customer.email')
                        ?? ($order->email ?? null)
                        ?? optional($order->user)->email;
                }

                $email = is_string($email) ? trim((string) $email) : '';
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($email === '') {
            Notification::make()
                ->warning()
                ->title('Appointment created but no email was found')
                ->body('Please enter the patient email on the appointment form (or ensure the appointment is linked to an order).')
                ->send();
            return;
        }

        \Log::info('appointment.created.email_attempt', [
            'appointment' => $record->id ?? null,
            'email' => $email,
            'order' => $order?->id ?? null,
        ]);

        $joinUrl = null;
        $startUrl = null;

        if ($isOnline && class_exists(\App\Services\ZoomMeetingService::class)) {
            try {
                $zoom = app(\App\Services\ZoomMeetingService::class);

                if (method_exists($zoom, 'createForAppointmentOnly')) {
                    $zoomInfo = $zoom->createForAppointmentOnly($record);
                } else {
                    $zoomInfo = $zoom->createForAppointment($record, $order);
                }

                if (is_array($zoomInfo)) {
                    if (! empty($zoomInfo['join_url'])) {
                        $joinUrl = (string) $zoomInfo['join_url'];
                    }

                    if (! empty($zoomInfo['start_url'])) {
                        $startUrl = (string) $zoomInfo['start_url'];
                    }

                    // If we found an order but the appointment is not linked, link it now so the table can read meta.
                    try {
                        if ($order && Schema::hasColumn('appointments', 'order_id') && empty($record->order_id)) {
                            $record->order_id = $order->id;
                        }

                        if ($order && Schema::hasColumn('appointments', 'order_reference')) {
                            $refNow = is_string($record->order_reference ?? null) ? trim((string) $record->order_reference) : '';
                            if ($refNow === '' && is_string($order->reference ?? null) && trim((string) $order->reference) !== '') {
                                $record->order_reference = trim((string) $order->reference);
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    // Save the host start URL onto the appointment if a compatible column exists.
                    if ($startUrl) {
                        foreach (['zoom_start_url', 'zoom_host_url', 'host_zoom_url', 'zoom_url'] as $col) {
                            if (Schema::hasColumn('appointments', $col)) {
                                $record->{$col} = $startUrl;
                                break;
                            }
                        }
                    }

                    // Save the join URL onto the appointment if a compatible column exists.
                    if ($joinUrl) {
                        foreach (['zoom_join_url', 'zoom_patient_url', 'join_zoom_url'] as $col) {
                            if (Schema::hasColumn('appointments', $col)) {
                                $record->{$col} = $joinUrl;
                                break;
                            }
                        }
                    }

                    // Always persist Zoom data on the appointment itself so the table can render Host link
                    try {
                        if (Schema::hasColumn('appointments', 'meta')) {
                            $ameta = is_array($record->meta) ? $record->meta : (json_decode($record->meta ?? '[]', true) ?: []);
                            $ameta['zoom'] = array_replace($ameta['zoom'] ?? [], [
                                'meeting_id' => $zoomInfo['id'] ?? null,
                                'join_url'   => $joinUrl,
                                'start_url'  => $startUrl,
                            ]);
                            $record->meta = $ameta;
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }

                    // Persist appointment changes.
                    if (($startUrl || $joinUrl) && method_exists($record, 'save')) {
                        try {
                            $record->save();
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }

                    // Persist into linked order meta too, so the Start Zoom column can read from order meta.
                    if ($order && ($startUrl || $joinUrl)) {
                        try {
                            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
                            $meta['zoom'] = array_replace($meta['zoom'] ?? [], [
                                'meeting_id' => $zoomInfo['id'] ?? null,
                                'join_url'   => $joinUrl,
                                'start_url'  => $startUrl,
                            ]);
                            $order->meta = $meta;
                            $order->save();
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }

                    \Log::info('appointment.zoom.created_from_admin', [
                        'appointment' => $record->id ?? null,
                        'order' => $order?->id ?? null,
                        'has_join_url' => (bool) $joinUrl,
                        'has_start_url' => (bool) $startUrl,
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('appointment.zoom.create_failed', [
                    'appointment' => $record->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $when = null;
        try {
            $startAtRaw = $record->getRawOriginal('start_at');
            $when = $startAtRaw
                ? Carbon::parse($startAtRaw)->tz('Europe/London')->format('d M Y, H:i')
                : null;
        } catch (\Throwable $e) {}

        $service = '';
        try {
            $sn = $record->getRawOriginal('service_name');
            $service = is_string($sn) ? trim((string) $sn) : '';
            if ($service === '') {
                $s2 = $record->getRawOriginal('service');
                $service = is_string($s2) ? trim((string) $s2) : '';
            }
        } catch (\Throwable $e) {
            $service = '';
        }

        $subject = 'Your appointment confirmation';

        $lines = [];
        $lines[] = 'Hello,';
        $lines[] = '';
        $lines[] = 'Your appointment has been booked' . ($service !== '' ? " for {$service}." : '.');
        if ($when) $lines[] = "When: {$when}";

        if ($isOnline && $joinUrl) {
            $lines[] = '';
            $lines[] = 'Your Zoom link:';
            $lines[] = $joinUrl;
        }

        $lines[] = '';
        $lines[] = 'If you need to rearrange, please contact the pharmacy.';

        $body = implode("\n", $lines);

        try {
            $fromAddress = config('mail.from.address') ?: 'info@pharmacy-express.co.uk';
            $fromName = config('mail.from.name') ?: 'Pharmacy Express';

            Mail::raw($body, function ($m) use ($email, $subject, $fromAddress, $fromName) {
                $m->from($fromAddress, $fromName)->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Appointment created but email could not be sent')
                ->body(substr($e->getMessage(), 0, 200))
                ->send();
            return;
        }

        Notification::make()
            ->success()
            ->title('Appointment created')
            ->body('Email sent to ' . $email . (($isOnline && $joinUrl) ? ' with Zoom link.' : '.') . (($isOnline && $startUrl) ? ' Zoom host link saved.' : ''))
            ->send();
    }
}