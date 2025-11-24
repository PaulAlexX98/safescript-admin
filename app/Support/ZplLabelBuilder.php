<?php

namespace App\Support;

use App\Models\Order;

class ZplLabelBuilder
{
    public function forOrder(Order|array $order): string
    {
        // Normalise input to simple fields irrespective of whether we got an Eloquent Order or an array payload
        if (is_array($order)) {
            $ref   = (string)($order['reference'] ?? $order['ref'] ?? 'REF');

            // allow either flat fields or nested shipping array
            $shipping = $order['shipping'] ?? $order['shipping_address'] ?? [];
            $first = (string)($order['first_name'] ?? ($shipping['first_name'] ?? ''));
            $last  = (string)($order['last_name'] ?? ($shipping['last_name'] ?? ''));
            $name  = trim($first . ' ' . $last);
            $addr1 = (string)($order['address1'] ?? ($shipping['address1'] ?? ''));
            $city  = (string)($order['city'] ?? ($shipping['city'] ?? ''));
            $post  = (string)($order['postcode'] ?? ($shipping['postcode'] ?? ''));
        } else {
            // Eloquent Order model path
            $ref   = (string)($order->reference ?? 'REF');
            $first = (string)($order->shipping_address->first_name ?? '');
            $last  = (string)($order->shipping_address->last_name ?? '');
            $name  = trim($first . ' ' . $last);
            $addr1 = (string)($order->shipping_address->address1 ?? '');
            $city  = (string)($order->shipping_address->city ?? '');
            $post  = (string)($order->shipping_address->postcode ?? '');
        }

        // fixed pharmacy sender
        $sender = 'SafeScript Pharmacy  Unit 4  WF1 2UY';

        // super simple 4x6 content
        $lines = [
            '^XA',
            '^PW812',
            '^LH0,0',
            '^CF0,40',
            '^FO40,40^FD' . $sender . '^FS',
            '^FO40,120^GB732,2,2^FS',

            '^CF0,70',
            '^FO40,160^FD' . $name . '^FS',
            '^CF0,50',
            '^FO40,230^FD' . $addr1 . '^FS',
            '^FO40,280^FD' . $city . '^FS',
            '^CF0,90',
            '^FO40,340^FD' . $post . '^FS',

            '^CF0,40',
            '^FO40,430^FDRef ' . $ref . '^FS',

            // code128 of ref
            '^BY3,2,120',
            '^FO40,480^BCN,120,Y,N,N',
            '^FD' . $ref . '^FS',

            '^XZ',
        ];

        return implode("\n", $lines);
    }
}