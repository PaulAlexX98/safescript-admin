<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Order;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function afterCreate(): void
    {
        $record = $this->record;
        if (! $record) return;

        $email = is_string($record->email ?? null) ? trim((string) $record->email) : '';

        $order = null;

        try {
            if (Schema::hasColumn('appointments', 'order_id') && ! empty($record->order_id)) {
                $order = Order::find($record->order_id);
            }
        } catch (\Throwable $e) {}

        try {
            if (! $order && Schema::hasColumn('appointments', 'order_reference')) {
                $ref = is_string($record->order_reference ?? null) ? trim($record->order_reference) : '';
                if ($ref !== '') {
                    $order = Order::query()->where('reference', $ref)->orderByDesc('id')->first();
                }
            }
        } catch (\Throwable $e) {}

        // If email was not entered on the appointment, try to derive it from the related order
        if ($email === '' && $order) {
            try {
                // Common patterns across this project
                if (isset($order->email) && is_string($order->email) && trim($order->email) !== '') {
                    $email = trim($order->email);
                } elseif (method_exists($order, 'patient') && $order->patient && isset($order->patient->email) && is_string($order->patient->email)) {
                    $email = trim((string) $order->patient->email);
                } elseif (method_exists($order, 'user') && $order->user && isset($order->user->email) && is_string($order->user->email)) {
                    $email = trim((string) $order->user->email);
                }
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

        if (class_exists(\App\Services\ZoomMeetingService::class)) {
            try {
                $zoom = app(\App\Services\ZoomMeetingService::class);

                if (method_exists($zoom, 'createForAppointmentOnly')) {
                    $zoomInfo = $zoom->createForAppointmentOnly($record);
                } else {
                    $zoomInfo = $zoom->createForAppointment($record, $order);
                }

                if (is_array($zoomInfo) && ! empty($zoomInfo['join_url'])) {
                    $joinUrl = (string) $zoomInfo['join_url'];
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
            $when = $record->start_at
                ? Carbon::parse($record->start_at)->tz('Europe/London')->format('d M Y, H:i')
                : null;
        } catch (\Throwable $e) {}

        $service = is_string($record->service_name ?? null) ? trim((string) $record->service_name) : '';
        if ($service === '') $service = is_string($record->service ?? null) ? trim((string) $record->service) : '';

        $subject = 'Your appointment confirmation';

        $lines = [];
        $lines[] = 'Hello,';
        $lines[] = '';
        $lines[] = 'Your appointment has been booked' . ($service !== '' ? " for {$service}." : '.');
        if ($when) $lines[] = "When: {$when}";

        if ($joinUrl) {
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
            ->body('Email sent to ' . $email . ($joinUrl ? ' with Zoom link.' : '.'))
            ->send();
    }
}