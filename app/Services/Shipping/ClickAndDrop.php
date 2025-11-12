<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ClickAndDrop
{
    public function createOrder(array $data): array
    {
        // Allow per-tenant overrides via $data while keeping config() as default
        $service = $data['service'] ?? config('clickanddrop.service');
        $package = $data['package'] ?? config('clickanddrop.package');
        $base    = $data['base']    ?? config('clickanddrop.base');
        $key     = $data['key']     ?? config('clickanddrop.key');

        $weight = (int) ($data['weight'] ?? 100);
        $value  = (float) ($data['value'] ?? 0);

        $payload = [
            'orders' => [[
                'orderReference' => $data['reference'],         // your order or consultation reference
                'channelName'    => 'Website',
                'orderDate'      => now()->toIso8601String(),

                'recipient' => [
                    'firstName' => $data['first_name'] ?? null,
                    'lastName'  => $data['last_name']  ?? null,
                    'email'     => $data['email']      ?? null,
                    'phone'     => $data['phone']      ?? null,
                    'address'   => [
                        'addressLine1' => $data['address1'],
                        'addressLine2' => $data['address2'] ?? null,
                        'town'         => $data['city'],
                        'county'       => $data['county'] ?? null,
                        'postcode'     => $data['postcode'],
                        'countryCode'  => 'GBR',
                    ],
                ],

                'packages' => [[
                    'weightInGrams'           => $weight,
                    'packageFormatIdentifier' => $package,
                    'contents' => [[
                        'name'              => $data['item_name'] ?? 'Private prescription',
                        'SKU'               => $data['sku'] ?? 'RX',
                        'quantity'          => 1,
                        'unitValue'         => $value,
                        'unitWeightInGrams' => $weight,
                        'originCountryCode' => 'GBR',
                    ]],
                ]],

                'postage' => [
                    'serviceIdentifier' => $service,
                ],

                'includeLabelInResponse' => true,
            ]],
        ];

        $res = Http::withHeaders([
                'Authorization' => $key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->baseUrl($base)
            // Royal Mail examples show a capitalised path
            ->post('Orders', $payload)
            ->throw(); // throws RequestException on 4xx 5xx

        $body = $res->json();

        $labelB64 = data_get($body, 'orders.0.label');
        $tracking  = data_get($body, 'orders.0.trackingNumber');

        if ($labelB64) {
            $pdf = base64_decode($labelB64);
            $path = "labels/{$data['reference']}.pdf";
            Storage::disk('local')->put($path, $pdf);
        }

        return [
            'tracking' => $tracking,
            'label_path' => isset($path) ? storage_path("app/{$path}") : null,
            'raw' => $body,
        ];
    }
}