@component('mail::message')
# Your order has been approved

Reference  {{ $order->reference ?? '' }}

Thank You

@endcomponent