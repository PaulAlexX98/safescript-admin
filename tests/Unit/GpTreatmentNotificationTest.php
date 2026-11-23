<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\ConsultationPdfController;
use App\Models\ConsultationSession;
use App\Models\Order;
use App\Models\User;
use App\Services\GpTreatmentNotification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GpTreatmentNotificationTest extends TestCase
{
    public function test_it_emails_the_registered_gp_with_the_treatment_pdf(): void
    {
        $transport = app('mailer')->getSymfonyTransport();
        $transport->flush();

        $order = new Order([
            'reference' => 'ORDER-123',
            'meta' => [
                'firstName' => 'Test',
                'lastName' => 'Patient',
            ],
        ]);
        $order->setRelation('user', new User([
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'gp_email' => 'practice@example.nhs.uk',
        ]));
        $session = new ConsultationSession;
        $session->id = 42;

        $pdfController = Mockery::mock(ConsultationPdfController::class);
        $pdfController->shouldReceive('notificationOfTreatmentIssued')
            ->once()
            ->with($session)
            ->andReturn(response('pdf-content', 200, ['Content-Type' => 'application/pdf']));

        $sent = (new GpTreatmentNotification($pdfController))
            ->sendForCompletedOrder($order, $session);

        $this->assertTrue($sent);
        $this->assertCount(1, $transport->messages());

        $message = $transport->messages()->last()->getOriginalMessage();
        $recipients = array_map(
            fn ($address): string => $address->getAddress(),
            $message->getTo(),
        );
        $attachments = $message->getAttachments();

        $this->assertSame(['practice@example.nhs.uk'], $recipients);
        $this->assertSame('Notification of Treatment - Test Patient', $message->getSubject());
        $this->assertCount(1, $attachments);
        $this->assertSame('Notification-of-Treatment-ORDER-123.pdf', $attachments[0]->getFilename());
        $this->assertNotNull(data_get($order->meta, 'gp_treatment_notification.sent_at'));
    }

    public function test_it_does_not_send_the_same_completed_order_twice(): void
    {
        $transport = app('mailer')->getSymfonyTransport();
        $transport->flush();

        $order = new Order([
            'reference' => 'ORDER-789',
            'meta' => [
                'gp_treatment_notification' => [
                    'sent_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $order->setRelation('user', new User([
            'gp_email' => 'practice@example.nhs.uk',
        ]));

        $pdfController = Mockery::mock(ConsultationPdfController::class);
        $pdfController->shouldNotReceive('notificationOfTreatmentIssued');

        $sent = (new GpTreatmentNotification($pdfController))
            ->sendForCompletedOrder($order, new ConsultationSession);

        $this->assertFalse($sent);
        $this->assertCount(0, $transport->messages());
    }

    public function test_it_fails_when_the_patient_has_no_registered_gp_email(): void
    {
        $order = new Order(['reference' => 'ORDER-456']);
        $order->setRelation('user', new User);

        $pdfController = Mockery::mock(ConsultationPdfController::class);
        $pdfController->shouldNotReceive('notificationOfTreatmentIssued');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid GP email is registered');

        (new GpTreatmentNotification($pdfController))
            ->sendForCompletedOrder($order, new ConsultationSession);
    }
}
