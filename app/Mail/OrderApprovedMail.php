<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderApprovedMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function build()
    {
        return $this->subject('Order approved ' . ($this->order->reference ?? ''))
            ->markdown('emails.order-approved', [
                'order' => $this->order,
            ]);
    }
}