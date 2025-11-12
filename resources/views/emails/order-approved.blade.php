@component('mail::message')
# Your order has been approved

Reference  {{ $order->reference ?? '' }}

We will contact you if we need anything else.

@endcomponent