<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Appointment;
use App\Models\Order;

class ZoomMeetingService
{
    protected string $accountId;
    protected string $clientId;
    protected string $clientSecret;
    protected string $oauthBase;
    protected string $apiBase;
    protected string $defaultUser;

    public function __construct()
    {
        $cfg = config('zoom');

        $this->accountId    = (string) ($cfg['account_id'] ?? '');
        $this->clientId     = (string) ($cfg['client_id'] ?? '');
        $this->clientSecret = (string) ($cfg['client_secret'] ?? '');
        $this->oauthBase    = (string) ($cfg['base_oauth'] ?? 'https://zoom.us/oauth/token');
        $this->apiBase      = (string) ($cfg['base_api'] ?? 'https://api.zoom.us/v2');
        $this->defaultUser  = (string) ($cfg['default_user'] ?? 'me');
    }

    protected function getAccessToken(): ?string
    {
        if (! $this->accountId || ! $this->clientId || ! $this->clientSecret) {
            return null;
        }

        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->oauthBase, [
                'grant_type' => 'account_credentials',
                'account_id' => $this->accountId,
            ]);

        if (! $response->ok()) {
            \Log::error('zoom.token_failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        return $response->json('access_token');
    }

    /**
     * Create a Zoom meeting for a weight management appointment
     * Returns an array with join_url and start_url if successful
     */
    public function createForAppointment(Appointment $appointment, ?Order $order = null): ?array
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return null;
        }

        // You may need to adjust these field names to match your appointments table
        $start = null;

        if ($appointment->start_at) {
            $start = Carbon::parse($appointment->start_at);
        } elseif ($appointment->date && $appointment->time) {
            $start = Carbon::parse($appointment->date . ' ' . $appointment->time);
        }

        if (! $start) {
            // Fall back to now plus fifteen minutes if no start stored
            $start = now()->addMinutes(15);
        }

        // Build a patient name for the Zoom topic (appointment fields first, then order/meta fallback)
        $patientName = '';

        $first = is_string($appointment->first_name ?? null) ? trim((string) $appointment->first_name) : '';
        $last  = is_string($appointment->last_name ?? null) ? trim((string) $appointment->last_name) : '';
        $patientName = trim($first . ' ' . $last);

        if ($patientName === '' && $order) {
            $of = is_string($order->first_name ?? null) ? trim((string) $order->first_name) : '';
            $ol = is_string($order->last_name ?? null) ? trim((string) $order->last_name) : '';
            $patientName = trim($of . ' ' . $ol);

            if ($patientName === '') {
                $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

                $mf = data_get($meta, 'patient.firstName')
                    ?? data_get($meta, 'patient.first_name')
                    ?? data_get($meta, 'firstName')
                    ?? data_get($meta, 'first_name');

                $ml = data_get($meta, 'patient.lastName')
                    ?? data_get($meta, 'patient.last_name')
                    ?? data_get($meta, 'lastName')
                    ?? data_get($meta, 'last_name');

                $mf = is_string($mf) ? trim($mf) : '';
                $ml = is_string($ml) ? trim($ml) : '';

                $patientName = trim($mf . ' ' . $ml);

                if ($patientName === '') {
                    $full = data_get($meta, 'patient.name')
                        ?? data_get($meta, 'full_name')
                        ?? data_get($meta, 'name');

                    $patientName = is_string($full) ? trim($full) : '';
                }
            }
        }

        $topic = 'Weight management consultation';
        if ($patientName !== '') {
            $topic .= ' for ' . $patientName;
        }
        if ($order && $order->reference) {
            $topic .= ' (' . $order->reference . ')';
        }

        $payload = [
            'topic'      => $topic,
            'type'       => 2, // scheduled meeting
            'start_time' => $start->copy()->setTimezone('UTC')->toIso8601String(),
            'duration'   => 20, // minutes
            'timezone'   => config('app.timezone', 'Europe/London'),
            'settings'   => [
                'join_before_host'   => false,
                'waiting_room'       => true,
                'approval_type'      => 0,
                'mute_upon_entry'    => false,
                'audio'              => 'both',
                'auto_recording'     => 'local',
            ],
        ];

        $response = Http::withToken($token)
            ->post($this->apiBase . '/users/' . urlencode($this->defaultUser) . '/meetings', $payload);

        // Treat any 2xx response (including 201 Created) as success
        if (! $response->successful()) {
            \Log::error('zoom.meeting_create_failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $data = $response->json() ?? [];

        \Log::info('zoom.meeting.created', [
            'appointment_id' => $appointment->getKey(),
            'order_id'       => $order?->getKey(),
            'zoom_id'        => $data['id'] ?? null,
            'join_url'       => $data['join_url'] ?? null,
            'status'         => $response->status(),
        ]);

        return [
            'id'        => $data['id'] ?? null,
            'join_url'  => $data['join_url'] ?? null,
            'start_url' => $data['start_url'] ?? null,
        ];
    }
}