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

        // Debug: log incoming patient fields and explicit shipping override
        Log::info('clickanddrop.payload.in', [
            'has_override_shipping' => isset($overrides['shipping']) && is_array($overrides['shipping']),
            'override_shipping'     => $overrides['shipping'] ?? null,
            'patient_shipping'      => [
                'shipping_address1' => data_get($patient, 'shipping_address1'),
                'shipping_address2' => data_get($patient, 'shipping_address2'),
                'shipping_city'     => data_get($patient, 'shipping_city'),
                'shipping_postcode' => data_get($patient, 'shipping_postcode'),
                'shipping_country'  => data_get($patient, 'shipping_country') ?? data_get($patient, 'shipping_country_code'),
                'nested'            => data_get($patient, 'shipping'),
            ],
            'patient_home'          => [
                'address1' => data_get($patient, 'address1'),
                'address2' => data_get($patient, 'address2'),
                'city'     => data_get($patient, 'city'),
                'postcode' => data_get($patient, 'postcode'),
                'country'  => data_get($patient, 'country') ?? data_get($patient, 'country_code'),
            ],
        ]);

       
        // 1 use explicit shipping override if present
        $explicit = (isset($overrides['shipping']) && is_array($overrides['shipping'])) ? $overrides['shipping'] : null;
        if ($explicit) {
            $addr1 = (string) ($explicit['address1'] ?? '');
            $addr2 = (string) ($explicit['address2'] ?? '');
            $city  = (string) ($explicit['city'] ?? '');
            $postcode = (string) ($explicit['postcode'] ?? '');
            $countryCode = strtoupper((string) ($explicit['country_code'] ?? 'GB'));
            $source = 'override';
        } else {
            // 2 try order meta shipping block with canonical keys
            $oMeta = is_array($order->meta ?? null) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);
            $mShip = (array) data_get($oMeta, 'shipping', []);
            $mHas  = ($mShip['address1'] ?? null) || ($mShip['city'] ?? null) || ($mShip['postcode'] ?? null);

            if ($mHas) {
                $addr1 = (string) ($mShip['address1'] ?? '');
                $addr2 = (string) ($mShip['address2'] ?? '');
                $city  = (string) ($mShip['city'] ?? '');
                $postcode = (string) ($mShip['postcode'] ?? '');
                $countryCode = strtoupper((string) ($mShip['country_code'] ?? 'GB'));
                $source = 'order_meta.shipping';
            } else {
                // 3 fall back to user shipping columns exactly
                $addr1 = (string) (data_get($order->user, 'shipping_address1') ?? '');
                $addr2 = (string) (data_get($order->user, 'shipping_address2') ?? '');
                $city  = (string) (data_get($order->user, 'shipping_city') ?? '');
                $postcode = (string) (data_get($order->user, 'shipping_postcode') ?? '');
                $countryCode = strtoupper((string) (data_get($order->user, 'shipping_country') ?? 'GB'));
                $source = 'user.shipping_*';

                // 4 if shipping empty, final fallback to home address
                if ($addr1 === '' && $city === '' && $postcode === '') {
                    $addr1 = (string) (data_get($order->user, 'address1') ?? '');
                    $addr2 = (string) (data_get($order->user, 'address2') ?? '');
                    $city  = (string) (data_get($order->user, 'city') ?? '');
                    $postcode = (string) (data_get($order->user, 'postcode') ?? '');
                    $countryCode = strtoupper((string) (data_get($order->user, 'country') ?? 'GB'));
                    $source = 'user.home';
                }
            }
        }

        // Normalise country code to ISO alpha-2 where possible
        $countryCode = $this->normalizeCountryCode($countryCode ?? '');

        // debug the chosen source and values
        \Log::info('clickanddrop.payload.resolved', [
            'source' => $source,
            'resolved' => compact('addr1','addr2','city','postcode','countryCode'),
        ]);
        
        // Resolve contact details with robust fallbacks
        // Priority: patient meta -> order meta shipping -> order user -> order root
        $oMeta = isset($oMeta) ? $oMeta : (is_array($order->meta ?? null) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []));
        $mShip = (array) data_get($oMeta, 'shipping', []);

        $email = (string) (
            data_get($patient, 'email') ?:
            data_get($mShip, 'email') ?:
            data_get($order, 'user.email') ?:
            data_get($order, 'email') ?:
            ''
        );

        $phoneRaw = (string) (
            data_get($patient, 'phone') ?:
            data_get($patient, 'mobile') ?:
            data_get($mShip, 'phone') ?:
            data_get($order, 'user.phone') ?:
            data_get($order, 'phone') ?:
            ''
        );

        // Light phone normalisation strip spaces and ensure leading +
        $phone = preg_replace('/\s+/', '', $phoneRaw ?? '');
        if ($phone && $phone[0] !== '+' && preg_match('/^0\d{9,}$/', $phone)) {
            // Assume UK if leading 0 and no country code
            $phone = '+44' . ltrim($phone, '0');
        }

        // Weight fallback — if you measure per item, sum; else default 100g
        $weightG = (int) (data_get($order, 'weight_g', 0));
        if ($weightG <= 0) {
            $weightG = 2000;
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
                        'countryCode' => $countryCode ?: 'GB',
                    ],
                    // Provide both common keys used by Royal Mail integrations
                    // so email/phone appear in Click & Drop UI regardless of schema variant.
                    'email' => $email,
                    'emailAddress' => $email,
                    'phone' => $phone,
                    'telephoneNumber' => $phone,
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

        Log::info('clickanddrop.contact', ['ref' => $ref, 'email' => $email, 'phone' => $phone]);

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

    /**
     * Attempt to coerce a human country string into ISO alpha-2 code.
     * Defaults to GB for any unrecognised/long value.
     */
    private function normalizeCountryCode(string $value): string
    {
        $v = strtoupper(trim($value));
        if ($v === '') {
            return 'GB';
        }
        // Already an alpha-2 code
        if (strlen($v) === 2) {
            return $v;
        }
        // Common mappings
        $map = [
            'UNITED KINGDOM' => 'GB',
            'UK' => 'GB',
            'GREAT BRITAIN' => 'GB',
            'ENGLAND' => 'GB',
            'SCOTLAND' => 'GB',
            'WALES' => 'GB',
            'NORTHERN IRELAND' => 'GB',
        ];
        return $map[$v] ?? 'GB';
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