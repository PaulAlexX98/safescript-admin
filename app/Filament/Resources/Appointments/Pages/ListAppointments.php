<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('Zoom meetings')
                ->url('https://zoom.us/meeting#/upcoming')
                ->openUrlInNewTab(),
            Actions\Action::make('send_today_reminders')
                ->label("Send today's reminders")
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading("Send today's appointment reminders")
                ->modalDescription('This will email every patient with an active appointment scheduled for today.')
                ->action(function (): void {
                    $dayStart = now('Europe/London')->startOfDay()->utc();
                    $dayEnd = now('Europe/London')->endOfDay()->utc();
                    $appointments = Appointment::query()
                        ->with('order.user')
                        ->whereBetween('start_at', [$dayStart, $dayEnd])
                        ->where(function ($query): void {
                            $query->whereNull('status')
                                ->orWhere('status', '')
                                ->orWhereNotIn('status', [
                                    'cancelled', 'canceled', 'void', 'failed', 'completed', 'complete', 'done',
                                ]);
                        })
                        ->get();

                    $sent = 0;
                    $skipped = 0;

                    foreach ($appointments as $appointment) {
                        if (AppointmentResource::hasUnpaidPayment($appointment)) {
                            $skipped++;
                            continue;
                        }

                        $order = $appointment->order;
                        $meta = is_array($order?->meta ?? null)
                            ? $order->meta
                            : (json_decode($order?->meta ?? '[]', true) ?: []);
                        $firstFilled = function (...$values): string {
                            foreach ($values as $value) {
                                $value = trim((string) $value);
                                if ($value !== '') {
                                    return $value;
                                }
                            }

                            return '';
                        };

                        $email = $firstFilled(
                            $appointment->email,
                            data_get($meta, 'patient.email'),
                            data_get($meta, 'customer.email'),
                            $order?->email,
                            optional($order?->user)->email,
                        );

                        if ($email === '') {
                            $skipped++;
                            continue;
                        }

                        $when = AppointmentResource::appointmentStartInLondon($appointment);
                        if (! $when) {
                            $skipped++;
                            continue;
                        }
                        $name = $firstFilled(
                            $appointment->patient_name,
                            trim((string) (($appointment->first_name ?? '') . ' ' . ($appointment->last_name ?? ''))),
                            data_get($meta, 'patient.first_name'),
                            data_get($meta, 'first_name'),
                            optional($order?->user)->first_name,
                        ) ?: 'there';
                        $reference = $firstFilled($appointment->order_reference, $order?->reference, $appointment->getKey());
                        $service = $firstFilled(
                            $appointment->service_name,
                            $appointment->service,
                            data_get($meta, 'service_name'),
                            data_get($meta, 'service'),
                        ) ?: 'Pharmacy Express';
                        $changeUrl = 'mailto:info@pharmacy-express.co.uk?subject='
                            . rawurlencode('Appointment change request – ' . $reference)
                            . '&body=' . rawurlencode('Hello Pharmacy Express,\n\nI would like to change my appointment on '
                                . $when->format('d M Y, H:i') . '.\n\nOrder reference: ' . $reference);
                        $subject = 'Appointment reminder – ' . $reference;

                        $body = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($subject) . '</title></head><body style="margin:0;padding:0;background:#f6f6f4;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f6f4;margin:0;padding:32px 12px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid rgba(18,63,64,.14);"><tr><td style="background:#123f40;padding:34px;border-bottom:4px solid #10c7a4;"><p style="margin:0 0 14px;font-family:Arial,sans-serif;font-size:12px;letter-spacing:.20em;text-transform:uppercase;color:#10c7a4;font-weight:700;">Pharmacy Express</p><h1 style="margin:0;font-family:Arial,sans-serif;font-size:34px;line-height:38px;color:#ffffff;">Appointment reminder</h1></td></tr><tr><td style="padding:34px;"><p style="margin:0 0 18px;font-family:Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">Hi ' . e($name) . ',</p><p style="margin:0 0 22px;font-family:Arial,sans-serif;font-size:16px;line-height:25px;color:#111827;">This is a reminder that your <strong>' . e($service) . '</strong> appointment is today at <strong>' . e($when->format('d M Y, H:i')) . '</strong>.</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef8f3;border:1px solid rgba(18,63,64,.16);margin:0 0 24px;"><tr><td style="padding:20px 24px;"><p style="margin:0 0 8px;font-family:Arial,sans-serif;font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#123f40;font-weight:700;">Appointment reference</p><p style="margin:0;font-family:Arial,sans-serif;font-size:20px;line-height:28px;color:#123f40;font-weight:700;">' . e($reference) . '</p></td></tr></table><p style="margin:0 0 18px;font-family:Arial,sans-serif;font-size:15px;line-height:23px;color:#334155;">If you need to change the time, please use the button below.</p><a href="' . e($changeUrl) . '" style="display:inline-block;background:#123f40;color:#ffffff;padding:14px 20px;text-decoration:none;font-family:Arial,sans-serif;font-size:15px;font-weight:700;">Request a time change</a></td></tr></table></td></tr></table></body></html>';

                        try {
                            Mail::html($body, function ($mail) use ($email, $subject): void {
                                $mail->from(config('mail.from.address') ?: 'info@pharmacy-express.co.uk', config('mail.from.name') ?: 'Pharmacy Express')
                                    ->to($email)
                                    ->subject($subject);
                            });
                            $sent++;
                        } catch (\Throwable $e) {
                            $skipped++;
                            \Log::warning('appointment.reminder_email_failed', [
                                'appointment_id' => $appointment->getKey(),
                                'email' => $email,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    Notification::make()
                        ->success()
                        ->title("Today's reminders sent")
                        ->body($sent . ' sent' . ($skipped ? '; ' . $skipped . ' skipped.' : '.'))
                        ->send();
                }),
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
