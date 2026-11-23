<?php

namespace App\Services;

use App\Http\Controllers\Admin\ConsultationPdfController;
use App\Models\ConsultationSession;
use App\Models\GpContact;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class GpTreatmentNotification
{
    public function __construct(
        protected ConsultationPdfController $pdfController,
    ) {}

    public function sendForCompletedOrder(Order $order, ConsultationSession $session): bool
    {
        $meta = is_array($order->meta)
            ? $order->meta
            : (json_decode($order->meta ?? '[]', true) ?: []);

        if (filled(data_get($meta, 'gp_treatment_notification.sent_at'))) {
            return false;
        }

        $email = $this->gpEmailFor($order);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No valid GP email is registered for this patient.');
        }

        $response = $this->pdfController->notificationOfTreatmentIssued($session);
        $pdfContent = method_exists($response, 'getContent') ? $response->getContent() : null;

        if (! is_string($pdfContent) || $pdfContent === '') {
            throw new RuntimeException('The Notification of Treatment PDF could not be generated.');
        }

        $patientName = trim(implode(' ', array_filter([
            data_get($meta, 'firstName') ?? data_get($meta, 'first_name') ?? $order->user?->first_name,
            data_get($meta, 'lastName') ?? data_get($meta, 'last_name') ?? $order->user?->last_name,
        ])));
        $subject = 'Notification of Treatment'.($patientName !== '' ? " - {$patientName}" : '');
        $body = "Dear GP surgery,\n\n"
            ."This patient received an assessment from our clinical team on the date shown above for weight management and was supplied the treatment ordered through our service. Please see the attached document.\n\n"
            ."W M Malik\n"
            .'MRPharmS';
        $reference = $order->reference ?? $order->getKey();
        $fromAddress = config('mail.from.address') ?: 'info@safescript.co.uk';
        $fromName = config('mail.from.name') ?: 'Safescript Pharmacy';

        Mail::send([], [], function ($mail) use ($email, $subject, $body, $pdfContent, $reference, $fromAddress, $fromName): void {
            $mail->from($fromAddress, $fromName)
                ->to($email)
                ->subject($subject)
                ->text($body)
                ->attachData(
                    $pdfContent,
                    "Notification-of-Treatment-{$reference}.pdf",
                    ['mime' => 'application/pdf'],
                );
        });

        data_set($meta, 'gp_treatment_notification.sent_at', now()->toIso8601String());
        $order->meta = $meta;

        if ($order->exists) {
            $order->saveQuietly();
        }

        return true;
    }

    protected function gpEmailFor(Order $order): ?string
    {
        $user = $order->user;
        $address = trim((string) ($user?->gp_surgery ?? ''));
        $email = $address !== '' ? GpContact::emailForAddress($address) : null;
        $email ??= $user?->gp_email;

        return filled($email) ? strtolower(trim((string) $email)) : null;
    }
}
