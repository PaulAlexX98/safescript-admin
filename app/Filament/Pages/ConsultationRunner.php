<?php

namespace App\Filament\Pages;

use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;
use App\Models\ApprovedOrder;
use App\Models\ConsultationSession;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Patient;
use App\Services\Shipping\ClickAndDrop;

class ConsultationRunner extends Page
{
    /** Don’t show in the sidebar; we only navigate here from an action. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Consultation';

    /** Use a Filament page blade under resources/views/consultations */
    protected string $view = 'consultations.layout';

    // Public props that the Blade will read via `$this->...`
    public ConsultationSession $session;
    public ?ApprovedOrder $order = null;
    public array $meta = [];
    public array $steps = [];
    public int $active = 0;

    public ?array $selected = null;
    public ?string $service = null;
    public ?string $treat = null;
    public ?string $variation = null;
    public ?string $total = null;

    public string $patientName = 'Unknown Patient';

    public string $tab = 'pharmacist-advice';

    /**
     * Livewire mount with route param {session}.
     * The route is registered in the AdminPanelProvider below.
     */
    public function mount(ConsultationSession $session, ?string $tab = null): void
    {
        // Load order and its user. If the User model uses SoftDeletes, include trashed users.
        $userClass = User::class;
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses($userClass) ?: []);

        if ($usesSoftDeletes) {
            // eager-load user including soft-deleted records
            $this->session = $session->load(['order.user' => fn ($q) => $q->withTrashed()]);
        } else {
            // regular eager load when SoftDeletes isn't enabled on User
            $this->session = $session->load('order.user');
        }

        $this->tab = $tab ? $tab : (string) (request()->query('tab') ?: $this->tab);

        $this->order   = $this->session->order;
        $this->meta    = $this->order?->meta ?? [];
        $this->steps   = array_keys($this->session->templates ?? []);
        $this->active  = (int) request()->integer('step', 0);

        $this->selected  = data_get($this->meta, 'selectedProduct') ?? data_get($this->meta, 'items.0');
        $this->service   = $this->order->service_name ?? data_get($this->meta, 'service') ?? 'Service';
        $this->treat     = $this->selected['name'] ?? null;
        $this->variation = $this->selected['variation'] ?? $this->selected['variations'] ?? null;
        $this->total     = isset($this->meta['totalMinor']) ? number_format($this->meta['totalMinor'] / 100, 2) : null;

        // Ensure shipping fields exist in session meta for Click & Drop
        try {
            $user = $this->order?->user;
            if ($user) {
                $meta = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []);

                // Build a shipping snapshot from the user profile
                $userShip = [
                    'address1' => $user->shipping_address1 ?? null,
                    'address2' => $user->shipping_address2 ?? null,
                    'city' => $user->shipping_city ?? null,
                    'postcode' => $user->shipping_postcode ?? null,
                    // Store ISO alpha-2 if available, else try country text
                    'country_code' => $user->shipping_country ?? ($user->country ?? null),
                ];

                // If user has no shipping set, fall back to their home address
                if (! ($userShip['address1'] ?? null)) {
                    $userShip['address1'] = $user->address1 ?? null;
                    $userShip['address2'] = $user->address2 ?? null;
                    $userShip['city'] = $user->city ?? null;
                    $userShip['postcode'] = $user->postcode ?? null;
                    $userShip['country_code'] = $userShip['country_code'] ?? ($user->country ?? null);
                }

                // Only inject keys that are missing in meta.shipping right now
                $existing = (array) data_get($meta, 'shipping', []);
                foreach ($userShip as $k => $v) {
                    if (! array_key_exists($k, $existing) || $existing[$k] === null || $existing[$k] === '') {
                        if ($v !== null && $v !== '') {
                            data_set($meta, 'shipping.' . $k, $v);
                        }
                    }
                }

                // Ensure contact details exist for Click & Drop
                $email = $user->email ?? data_get($meta, 'patient.email');
                $phone = $user->phone ?? data_get($meta, 'patient.phone');

                if ($email && ! data_get($meta, 'patient.email')) {
                    data_set($meta, 'patient.email', $email);
                }
                if ($phone && ! data_get($meta, 'patient.phone')) {
                    data_set($meta, 'patient.phone', $phone);
                }
                // Mirror to shipping.* for services that read contact from shipping
                if ($email && ! data_get($meta, 'shipping.email')) {
                    data_set($meta, 'shipping.email', $email);
                }
                if ($phone && ! data_get($meta, 'shipping.phone')) {
                    data_set($meta, 'shipping.phone', $phone);
                }

                // Also mirror to patient.shipping_* for legacy readers
                $map = [
                    'address1' => 'patient.shipping_address1',
                    'address2' => 'patient.shipping_address2',
                    'city' => 'patient.shipping_city',
                    'postcode' => 'patient.shipping_postcode',
                    'country_code' => 'patient.shipping_country_code',
                ];
                foreach ($map as $src => $dst) {
                    $val = data_get($meta, 'shipping.' . $src);
                    if ($val !== null && $val !== '' && data_get($meta, $dst) === null) {
                        data_set($meta, $dst, $val);
                    }
                }

                $this->meta = $meta; // keep in-memory; it will be persisted by syncMetaToOrder()
            }
        } catch (\Throwable $e) {
            // Non-fatal: do not block the page if we can't hydrate shipping
        }

        // Compute a reliable display name once
        $this->patientName = $this->resolvePatientName();
    }

    protected function getViewData(): array
    {
        return [
            'session' => $this->session,
            'currentTab' => $this->tab,
        ];
    }
    public function getHeading(): string
    {
        return 'Consulting ' . $this->patientName;
    }

    protected function resolvePatientName(): string
    {
        $user = $this->order?->user;

        $full =
            $user?->full_name
            ?: trim(implode(' ', array_filter([$user?->first_name ?? null, $user?->last_name ?? null])))
            ?: data_get($this->session, 'patient_name')
            ?: data_get($this->meta, 'patient.full_name')
            ?: data_get($this->meta, 'patient.name')
            ?: data_get($this->meta, 'shipping.name')
            ?: data_get($this->meta, 'billing.name')
            ?: '';

        return $full !== '' ? $full : 'Unknown Patient';
    }

    public function getTitle(): string
    {
        // Keep the browser/tab title in sync with the visible heading
        return $this->getHeading();
    }
    
    protected function syncMetaToOrder(): void
    {
        if ($this->order) {
            $meta = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []);
            $this->order->meta = $meta;
            $this->order->save();
        }
    }

    public function saveConsultation(): void
    {
        $this->syncMetaToOrder();
        // Optional UX feedback
        Notification::make()
            ->title('Saved')
            ->success()
            ->send();
    }

    public function saveAndNext(): void
    {
        $this->syncMetaToOrder();

        Notification::make()
            ->title('Saved')
            ->success()
            ->send();

        // Advance using the templates order if available; fall back to current tab list
        $keys = array_values($this->steps ?? []);
        $currentIndex = (int) ($this->active ?? 0);
        $nextIndex = $currentIndex + 1;

        if (isset($keys[$nextIndex])) {
            $this->active = $nextIndex;
            $this->tab = (string) $keys[$nextIndex];

            // Redirect so URL and Blade include stay in sync
            $url = request()->url() . '?' . http_build_query(['tab' => $this->tab, 'step' => $this->active]);
            $this->redirect($url);
        } else {
            Notification::make()
                ->title('No further steps')
                ->info()
                ->send();
        }
    }
    public function completeConsultation(): void
    {
        // Always persist any unsaved meta from the UI first
        $this->syncMetaToOrder();

        DB::transaction(function () {
            // 1) Mark the consultation session as completed (if the column exists)
            if (Schema::hasColumn($this->session->getTable(), 'completed_at')) {
                $this->session->completed_at = now();
                $this->session->save();
            }

            // 2) Move the underlying ApprovedOrder to completed and append an audit line in meta
            if ($this->order) {
                // Ensure meta is an array and append a completion note
                $meta = is_array($this->order->meta) ? $this->order->meta : (json_decode($this->order->meta ?? '[]', true) ?: []);
                $existing = (string) (data_get($meta, 'completion_notes', '') ?? '');
                $lines = preg_split("/\r\n|\n|\r/", $existing, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $lines[] = now()->format('d-m-Y H:i') . ': Consultation completed';
                data_set($meta, 'completion_notes', implode("\n", $lines));
                data_set($meta, 'completed_at', now()->toISOString());
                $this->order->meta = $meta;

                // Set statuses
                $this->order->status = 'completed';
                if (Schema::hasColumn($this->order->getTable(), 'booking_status')) {
                    $this->order->booking_status = 'completed';
                }
                if (Schema::hasColumn($this->order->getTable(), 'completed_at')) {
                    $this->order->completed_at = now();
                }
                $this->order->save();

                // 3) Mirror the change to the real Orders table if present (by reference)
                try {
                    if (class_exists(Order::class)) {
                        $ref = $this->order->reference ?? $this->order->id;
                        /** @var Order|null $order */
                        $order = Order::where('reference', $ref)->first();
                        if ($order) {
                            $oMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

                            // Carry over the same completion note
                            $oExisting = (string) (data_get($oMeta, 'completion_notes', '') ?? '');
                            $oLines = preg_split("/\r\n|\n|\r/", $oExisting, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            $oLine = now()->format('d-m-Y H:i') . ': Consultation completed';
                            if (!in_array($oLine, $oLines, true)) {
                                $oLines[] = $oLine;
                            }
                            data_set($oMeta, 'completion_notes', implode("\n", $oLines));
                            data_set($oMeta, 'completed_at', now()->toISOString());

                            $payload = [
                                'status' => 'completed',
                                'meta'   => $oMeta,
                            ];
                            if (Schema::hasColumn($order->getTable(), 'booking_status')) {
                                $payload['booking_status'] = 'completed';
                            }
                            if (Schema::hasColumn($order->getTable(), 'completed_at')) {
                                $payload['completed_at'] = now();
                            }

                            $order->forceFill($payload)->save();
                        }
                    }
                } catch (Throwable $e) {
                    // swallow to avoid breaking UX; consider logging if required
                }
            }
        });

        // 4) Trigger Royal Mail Click & Drop shipping now that completion has been saved
        try {
            \Log::info('clickanddrop.livewire.start', [
                'session' => $this->session->id,
            ]);

            // Normalise meta pulled from the order we mounted
            $meta = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []);

            $first = data_get($meta, 'patient.first_name')
                ?? data_get($this->order?->user, 'first_name');
            $last  = data_get($meta, 'patient.last_name')
                ?? data_get($this->order?->user, 'last_name');

            // Build explicit shipping block for Click & Drop (shipping-first, fallback to user shipping)
            // Build explicit shipping block using canonical keys only
            $ship = [
                'address1'     => data_get($this->meta, 'shipping.address1')
                                    ?? data_get($this->order?->user, 'shipping_address1'),
                'address2'     => data_get($this->meta, 'shipping.address2')
                                    ?? data_get($this->order?->user, 'shipping_address2'),
                'city'         => data_get($this->meta, 'shipping.city')
                                    ?? data_get($this->order?->user, 'shipping_city'),
                'postcode'     => data_get($this->meta, 'shipping.postcode')
                                    ?? data_get($this->order?->user, 'shipping_postcode'),
                'country_code' => strtoupper(
                                    data_get($this->meta, 'shipping.country_code')
                                    ?? data_get($this->order?->user, 'shipping_country')
                                    ?? 'GB'
                                ),
                'email'       => data_get($this->meta, 'patient.email')
                                    ?? data_get($this->order?->user, 'email'),
                'phone'       => data_get($this->meta, 'patient.phone')
                                    ?? data_get($this->order?->user, 'phone'),
            ];

            // Build a lightweight patient object for the shipping service
            $patientObj = (object) [
                'first_name'        => $first,
                'last_name'         => $last,
                'email'             => data_get($meta, 'patient.email') ?? data_get($this->order?->user, 'email'),
                'phone'             => data_get($meta, 'patient.phone') ?? data_get($this->order?->user, 'phone'),
                // Home fields kept for completeness
                'address1'          => data_get($this->order?->user, 'address1'),
                'address2'          => data_get($this->order?->user, 'address2'),
                'city'              => data_get($this->order?->user, 'city'),
                'postcode'          => data_get($this->order?->user, 'postcode'),
                'country'           => data_get($this->order?->user, 'country'),
                // Shipping snapshot on the patient
                'shipping_address1' => $ship['address1'] ?? null,
                'shipping_address2' => $ship['address2'] ?? null,
                'shipping_city'     => $ship['city'] ?? null,
                'shipping_postcode' => $ship['postcode'] ?? null,
                'shipping_country'  => $ship['country_code'] ?? null,
                'shipping'          => $ship,
            ];

            // Log the call mode and shipping override
            \Log::info('clickanddrop.runner.call', [
                'mode'          => 'order+patient+overrides',
                'order_ref'     => $this->order?->reference,
                'ship_override' => $ship,
            ]);

            $out = app(\App\Services\Shipping\ClickAndDrop::class)->createOrder(
                $this->order,
                $patientObj,
                [
                    'shipping' => $ship,
                    'contact'  => [
                        'email' => $ship['email'] ?? ($patientObj->email ?? null),
                        'phone' => $ship['phone'] ?? ($patientObj->phone ?? null),
                    ],
                ]
            );

            // Persist shipping details onto the order meta for visibility and PDFs
            $oMeta = is_array($this->order?->meta) ? $this->order->meta : (json_decode($this->order->meta ?? '[]', true) ?: []);
            data_set($oMeta, 'shipping.carrier', 'royal_mail_click_and_drop');
            data_set($oMeta, 'shipping.tracking', $out['tracking'] ?? null);
            data_set($oMeta, 'shipping.label', $out['label_path'] ?? null);
            data_set($oMeta, 'shipping.raw', $out['raw'] ?? null);
            // Persist contact details into order meta for PDFs and admin
            if (! data_get($oMeta, 'shipping.email') && ($ship['email'] ?? null)) {
                data_set($oMeta, 'shipping.email', $ship['email']);
            }
            if (! data_get($oMeta, 'shipping.phone') && ($ship['phone'] ?? null)) {
                data_set($oMeta, 'shipping.phone', $ship['phone']);
            }
            if (! data_get($oMeta, 'patient.email') && ($patientObj->email ?? null)) {
                data_set($oMeta, 'patient.email', $patientObj->email);
            }
            if (! data_get($oMeta, 'patient.phone') && ($patientObj->phone ?? null)) {
                data_set($oMeta, 'patient.phone', $patientObj->phone);
            }
            if ($this->order) {
                $this->order->meta = $oMeta;
                $this->order->save();
            }

            \Log::info('clickanddrop.livewire.ok', [
                'session'  => $this->session->id,
                'tracking' => $out['tracking'] ?? null,
            ]);

            // Optional success toast so you know a label was created
            Notification::make()
                ->title('Royal Mail label created')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            \Log::error('clickanddrop.livewire.failed', [
                'session' => $this->session->id,
                'error'   => $e->getMessage(),
            ]);
            // Non-blocking failure notification
            Notification::make()
                ->title('Royal Mail shipping failed')
                ->danger()
                ->send();
        }

        // UX: toast + redirect to Completed Orders list
        Notification::make()
            ->title('Consultation completed')
            ->body('Order moved to Completed.')
            ->success()
            ->send();

        // Try Filament resource route, then fallbacks
        if (Route::has('filament.admin.resources.orders.index')) {
            $this->redirect(route('filament.admin.resources.orders.index', [
                'tableFilters[status][value]' => 'completed',
            ]));
            return;
        }

        if (Route::has('orders.index')) {
            $this->redirect(route('orders.index', ['status' => 'completed']));
            return;
        }

        $this->redirect(url('/admin/orders?status=completed'));
    }
}