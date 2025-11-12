<?php

namespace App\Services\Shipping;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ClickAndDrop
{
    /**
     * Remove null/empty-string entries recursively so the API doesn't receive invalid fields.
     */
    private function clean(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = $this->clean($v);
                if ($v === []) { continue; }
                $out[$k] = $v;
            } else {
                if ($v === null) { continue; }
                if (is_string($v) && trim($v) === '') { continue; }
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Build a curl command string for local debugging only. */
    private function buildCurl(string $url, array $headers, array $payload): string
    {
        $h = '';
        foreach ($headers as $k => $v) {
            $h .= ' -H ' . escapeshellarg($k . ': ' . $v);
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return 'curl -i -X POST ' . escapeshellarg($url) . $h . ' -d ' . escapeshellarg($json);
    }

    public function createOrder(array $data): array
    {
        // Allow per-tenant overrides via $data while keeping config() as default
        $service = $data['service'] ?? config('clickanddrop.service');
        $package = $data['package'] ?? config('clickanddrop.package');
        $base    = $data['base']    ?? config('clickanddrop.base');
        $key     = $data['key']     ?? config('clickanddrop.key');

        $weight = (int) ($data['weight'] ?? 100);
        $value  = (float) ($data['value'] ?? 0);
        $value = round($value, 2);

        $payload = $this->clean([
            'orders' => [[
                'orderReference' => (string) $data['reference'],
                'channelName'    => 'Website',
                'orderDate'      => now()->toIso8601String(),

                'recipient' => [
                    'firstName' => $data['first_name'] ?? null,
                    'lastName'  => $data['last_name']  ?? null,
                    'email'     => $data['email']      ?? null,
                    'phone'     => $data['phone']      ?? null,
                    'address'   => [
                        'addressLine1' => $data['address1'] ?? null,
                        'addressLine2' => $data['address2'] ?? null,
                        'town'         => $data['city']     ?? null,
                        'county'       => $data['county']   ?? null,
                        'postcode'     => $data['postcode'] ?? null,
                        'countryCode'  => $data['country']  ?? 'GB',
                    ],
                ],

                'packages' => [[
                    'weightInGrams'           => (int) $weight,
                    'packageFormatIdentifier' => (string) $package,
                    'contents' => [[
                        'name'              => $data['item_name'] ?? 'Private prescription',
                        'SKU'               => $data['sku'] ?? 'RX',
                        'quantity'          => 1,
                        'unitValue'         => (float) $value,
                        'unitWeightInGrams' => (int) $weight,
                        'originCountryCode' => $data['origin'] ?? 'GB',
                    ]],
                ]],

                'postage' => [
                    'serviceIdentifier' => (string) $service,
                ],

                'includeLabelInResponse' => true,
            ]],
        ]);

        $headers = [
            'Authorization' => $key,
            'Accept'        => 'application/json',
        ];

        // Optional one-line curl log for LOCAL troubleshooting if explicitly enabled
        if (app()->isLocal() && (bool) config('clickanddrop.log_curl', false)) {
            $url = rtrim($base, '/') . '/Orders';
            \Log::info('clickanddrop.curl', ['cmd' => $this->buildCurl($url, $headers, $payload)]);
        }

        try {
            $res = Http::withHeaders($headers)
                ->baseUrl($base)
                ->acceptJson()
                ->asJson()
                ->post('Orders', $payload);
        } catch (RequestException $e) {
            \Log::error('clickanddrop.http.exception', [
                'reference' => $data['reference'] ?? null,
                'message'   => $e->getMessage(),
                'response'  => optional($e->response)->body(),
            ]);
            throw $e;
        }

        $res->throw();

        if (! $res->successful()) {
            \Log::error('clickanddrop.failed', [
                'reference' => $data['reference'] ?? null,
                'status'    => $res->status(),
                'body'      => $res->body(),
            ]);
        }

        $body = [];
        try { $body = $res->json(); } catch (\Throwable $e) {
            $raw = $res->body();
            if (is_string($raw) && $raw !== '') {
                try { $body = json_decode($raw, true) ?: []; } catch (\Throwable $e2) { $body = []; }
            }
        }

        $labelB64 = data_get($body, 'orders.0.label');
        $tracking  = data_get($body, 'orders.0.trackingNumber');

        if ($labelB64) {
            $pdf = base64_decode($labelB64);
            $path = "labels/{$data['reference']}.pdf";
            if (!empty($pdf)) {
                Storage::disk('local')->put($path, $pdf);
            }
        }

        return [
            'tracking' => $tracking,
            'label_path' => isset($path) ? storage_path("app/{$path}") : null,
            'raw' => $body,
        ];
    }
}