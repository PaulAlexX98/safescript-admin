<?php

namespace App\Services\Shipping;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ClickAndDrop
{
    /**
     * Create a Royal Mail Click & Drop order for the given order/patient.
     *
     * $overrides may contain:
     *  - api_key
     *  - base
     *  - service_identifier
     *  - package_identifier
     *  - sender (optional array for future extension)
     */
    public function createOrder(object $order, object $patient, array $overrides = []): array
    {
        // Resolve credentials and defaults (multi-tenant capable via $overrides)
        // Use the dedicated clickanddrop config, which reads CLICK_AND_DROP_* env vars.
        $base   = rtrim(
            (string) ($overrides['base'] ?? config('clickanddrop.base') ?? 'https://api.parcel.royalmail.com/api/v1'),
            '/'
        );
        $apiKey = (string) ($overrides['api_key'] ?? config('clickanddrop.key'));
        $serviceId = (string) ($overrides['service_identifier'] ?? config('clickanddrop.default_service', 'RM24'));
        $packageId = (string) ($overrides['package_identifier'] ?? config('clickanddrop.default_package', 'Parcel'));

        if ($apiKey === '') {
            throw new RuntimeException('Click & Drop API key is missing.');
        }

        // Pull safe fields from $order and $patient without assuming strict types
        $ref       = (string) (data_get($order, 'reference') ?? data_get($order, 'ref') ?? 'REF-' . uniqid());
        $createdAt = data_get($order, 'created_at');
        $orderDate = is_string($createdAt)
            ? $createdAt
            : (method_exists($createdAt, 'toIso8601String') ? $createdAt->toIso8601String() : now()->toIso8601String());

        $subtotal  = (float) (data_get($order, 'subtotal', 0));
        $shipCost  = (float) (data_get($order, 'shipping_total', 0));
        $total     = (float) (data_get($order, 'total', $subtotal + $shipCost));

        $firstName = trim((string) data_get($patient, 'first_name', ''));
        $lastName  = trim((string) data_get($patient, 'last_name', ''));
        $fullName  = trim($firstName . ' ' . $lastName) ?: (string) data_get($patient, 'name', 'Patient');

        $addr1   = (string) data_get($patient, 'address1', data_get($patient, 'address.line1'));
        $addr2   = (string) (data_get($patient, 'address2', data_get($patient, 'address.line2')) ?? '');
        $city    = (string) (data_get($patient, 'city', data_get($patient, 'address.city')) ?? data_get($patient, 'town', ''));
        $postcode= (string) (data_get($patient, 'postcode', data_get($patient, 'address.postcode')) ?? '');
        $country = (string) (data_get($patient, 'country_code', data_get($patient, 'address.country_code')) ?? 'GB');
        $email   = (string) (data_get($patient, 'email') ?? '');
        $phone   = (string) (data_get($patient, 'phone') ?? data_get($patient, 'mobile', ''));

        // Weight fallback — if you measure per item, sum; else default 100g
        $weightG = (int) (data_get($order, 'weight_g', 0));
        if ($weightG <= 0) {
            $weightG = 1000;
        }

        // Build the minimal working payload structure (matches your successful curl)
        $payload = [
            'items' => [[
                'orderReference' => $ref,
                'orderDate' => $this->iso8601($orderDate),
                'subtotal' => $subtotal,
                'shippingCostCharged' => $shipCost,
                'total' => $total,
                'recipient' => [
                    'address' => [
                        'fullName' => $fullName,
                        'addressLine1' => $addr1,
                        'addressLine2' => $addr2,
                        'city' => $city,
                        'postcode' => $postcode,
                        'countryCode' => strtoupper($country ?: 'GB'),
                    ],
                    'email' => $email,
                    'phone' => $phone,
                ],
                'packages' => [[
                    'packageFormatIdentifier' => $packageId,
                    'weightInGrams' => $weightG,
                ]],
                'postage' => [
                    'serviceIdentifier' => $serviceId,
                ],
                // Ask the API to include label immediately when supported
                'includeLabelInResponse' => true,
            ]],
        ];

        // Fire the request using Bearer auth and JSON body
        $url = $base . '/orders';

        $res = Http::asJson()
            ->withToken($apiKey) // Authorization: Bearer <key>
            ->acceptJson()
            ->post($url, $payload);

        // Minimal logging; avoid dumping full payload to logs in production
        Log::info('clickanddrop.create', [
            'ref' => $ref,
            'status' => $res->status(),
            'ok' => $res->successful(),
        ]);

        if (! $res->successful()) {
            throw new RuntimeException('Click & Drop error ' . $res->status() . ': ' . $res->body());
        }

        $data = $res->json();

        Log::info('clickanddrop.response', [
            'data' => $data,
        ]);

        // Persist any inline label/document returned
        $saved = [];
        $docs = (array) data_get($data, 'createdOrders.0.generatedDocuments', []);
        foreach ($docs as $i => $doc) {
            $bytesB64 = data_get($doc, 'data') ?? data_get($doc, 'bytes') ?? null;
            $ext = strtolower((string) data_get($doc, 'fileExtension', 'pdf'));
            if (is_string($bytesB64) && $bytesB64 !== '') {
                $path = 'labels/' . $ref . '-' . ($i + 1) . '.' . $ext;
                Storage::disk('local')->put($path, base64_decode($bytesB64));
                $saved[] = $path;
            }
        }

        return [
            'request' => $payload,
            'response' => $data,
            'label_paths' => $saved,
        ];
    }

    private function iso8601($value): string
    {
        if (is_string($value)) {
            // Try to normalise arbitrary strings
            try {
                return \Carbon\Carbon::parse($value)->toIso8601String();
            } catch (\Throwable $e) {
                return now()->toIso8601String();
            }
        }
        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }
        return now()->toIso8601String();
    }
}