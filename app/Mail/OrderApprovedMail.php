<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->onQueue('admin-mail');
    }

    public function build()
    {
        return $this->subject('Order approved ' . ($this->order->reference ?? ''))
            ->markdown('emails.order-approved', [
                'order' => $this->order,
            ]);
    }
}