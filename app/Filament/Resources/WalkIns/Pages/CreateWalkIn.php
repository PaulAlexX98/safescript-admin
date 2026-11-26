<?php

namespace App\Filament\Resources\WalkIns\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\PendingOrders\Widgets\ManualAppointmentsPending;
use App\Filament\Resources\WalkIns\Actions\StartWalkInConsultation;
use App\Filament\Resources\WalkIns\Schemas\WalkInForm;
use App\Filament\Resources\WalkIns\WalkInResource;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use App\Models\Order;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Locked;
use Throwable;

class CreateWalkIn extends CreateRecord
{
    protected static string $resource = WalkInResource::class;

    protected static ?string $title = 'Create Walk In';

    #[Locked]
    public ?int $sourceAppointmentId = null;

    protected ?Appointment $sourceAppointment = null;

    protected bool $startConsultationAfterCreate = false;

    public function mount(): void
    {
        parent::mount();

        if (! request()->query->has('appointment')) {
            return;
        }

        $this->sourceAppointmentId = filter_var(request()->query('appointment'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) ?: null;
        abort_unless($this->sourceAppointmentId, 404);

        $appointment = $this->findSourceAppointment();
        abort_unless($appointment, 404);

        if ($appointment->order_id) {
            abort_unless($appointment->order && WalkInResource::canView($appointment->order), 403);
            $this->redirect(WalkInResource::getUrl('view', ['record' => $appointment->order]));

            return;
        }

        $this->form->fill(array_replace($this->data ?? [], $this->appointmentFormData($appointment)));
    }

    public function canCreateAnother(): bool
    {
        return $this->sourceAppointmentId === null && parent::canCreateAnother();
    }

    protected function getFormActions(): array
    {
        if ($this->sourceAppointmentId === null) {
            return parent::getFormActions();
        }

        return [
            $this->getCreateFormAction(),
            StartWalkInConsultation::make()->action('createAndStartConsultation'),
            $this->getCancelFormAction(),
        ];
    }

    public function createAndStartConsultation(): void
    {
        abort_if($this->sourceAppointmentId === null, 404);

        $this->startConsultationAfterCreate = true;
        try {
            $this->create();
        } finally {
            $this->startConsultationAfterCreate = false;
        }
    }

    public function hasDatabaseTransactions(): bool
    {
        return $this->sourceAppointmentId !== null || parent::hasDatabaseTransactions();
    }

    protected function findSourceAppointment(bool $lock = false): ?Appointment
    {
        $appointment = ManualAppointmentsPending::query()
            ->select('appointments.*')
            ->whereKey($this->sourceAppointmentId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($appointment) {
            abort_if($appointment->source === 'partner_api', 404);
            AppointmentResource::authorizeEdit($appointment);
        }

        return $appointment;
    }

    protected function appointmentFormData(Appointment $appointment): array
    {
        $data = $appointment->only(['first_name', 'last_name', 'email', 'phone']);
        $data['appointment_time_display'] = $this->appointmentStart($appointment)->tz('Europe/London')->format('d M Y, H:i');
        $data['appointment_notes'] = $appointment->notes;

        if (! SchemaFacade::hasTable('services')) {
            return $data;
        }

        $service = $appointment->service_id
            ? DB::table('services')->find($appointment->service_id)
            : null;
        if (! $service && filled($appointment->service_slug) && SchemaFacade::hasColumn('services', 'slug')) {
            $service = DB::table('services')->where('slug', $appointment->service_slug)->first();
        }
        if (! $service && filled($appointment->service)) {
            $service = DB::table('services')->where('name', $appointment->service)->first();
        }
        if (! $service) {
            return $data;
        }

        $data['service_id'] = $service->id;
        $data['service_name'] = $service->name;
        $data['service_slug'] = $service->slug ?? null;

        $itemOptions = WalkInForm::itemOptions($service->id);
        foreach (['order_item', 'item', 'item_name', 'product_name', 'medicine_name', 'service_name'] as $column) {
            $itemName = trim((string) $appointment->getAttribute($column));
            if (! isset($itemOptions[$itemName])) {
                continue;
            }

            $variation = trim((string) ($appointment->variation ?? $appointment->variant ?? $appointment->strength ?? $appointment->dose ?? ''));
            $matchedVariation = $variation !== '' && isset(WalkInForm::variationOptions($itemName)[$variation]);
            $price = $appointment->unit_price ?? $appointment->price;
            $data['items'] = [[
                'name' => $itemName,
                'variation' => $matchedVariation ? $variation : null,
                'qty' => 1,
                'unit_price' => ($variation === '' || $matchedVariation) && is_numeric($price) ? $price : null,
            ]];
            break;
        }

        return $data;
    }

    protected function appointmentStart(Appointment $appointment): Carbon
    {
        return Carbon::parse($appointment->getRawOriginal('start_at'), 'UTC');
    }

    public function getHeading(): string
    {
        return 'Create Walk In';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($this->sourceAppointmentId !== null) {
            $this->sourceAppointment = $this->findSourceAppointment(lock: true);
            if (! $this->sourceAppointment || $this->sourceAppointment->order_id) {
                Notification::make()
                    ->warning()
                    ->title('This appointment is no longer available to start a walk-in order')
                    ->send();
                $this->halt(shouldRollbackDatabaseTransaction: true);
            }

            $data['appointment_at'] = $this->appointmentStart($this->sourceAppointment)->toIso8601String();
        }

        $patientWasSelected = ! empty($data['patient_id']);
        $patientWasOriginallySelected = $patientWasSelected;
        if (! $patientWasSelected) {
            $manualFirstName = trim((string) ($data['first_name'] ?? ''));
            $manualLastName = trim((string) ($data['last_name'] ?? ''));
            $manualEmail = trim((string) ($data['email'] ?? ''));
            $manualPhone = trim((string) ($data['phone'] ?? ''));

            $hasManualPatientData = $manualFirstName !== ''
                || $manualLastName !== ''
                || $manualEmail !== ''
                || $manualPhone !== '';

            if ($hasManualPatientData) {
                $matchedUser = null;

                if ($manualEmail !== '') {
                    $matchedUser = User::query()->where('email', $manualEmail)->first();
                }

                if (! $matchedUser && $manualPhone !== '') {
                    $matchedUser = User::query()->where('phone', $manualPhone)->first();
                }

                if (! $matchedUser) {
                    $matchedUser = User::query()->create([
                        'name' => trim((string) (((($data['first_name'] ?? '') ?: '') . ' ' . (($data['last_name'] ?? '') ?: '')))),
                        'first_name' => (($data['first_name'] ?? null) ?: null),
                        'last_name' => (($data['last_name'] ?? null) ?: null),
                        'gender' => (($data['gender'] ?? null) ?: null),
                        'phone' => (($data['phone'] ?? null) ?: null),
                        'dob' => (($data['dob'] ?? null) ?: null),
                        'email' => (($data['email'] ?? null) ?: null),
                        'address1' => (($data['address_line_1'] ?? null) ?: null),
                        'address2' => (($data['address_line_2'] ?? null) ?: null),
                        'city' => (($data['city'] ?? null) ?: null),
                        'county' => (($data['county'] ?? null) ?: null),
                        'postcode' => (($data['postcode'] ?? null) ?: null),
                        'country' => (($data['country'] ?? null) ?: 'United Kingdom'),
                        'password' => Hash::make(Str::random(40)),
                    ]);
                }

                $patient = Patient::query()->create([
                    'user_id' => $matchedUser?->id,
                    'first_name' => (($data['first_name'] ?? null) ?: $matchedUser?->first_name ?: null),
                    'last_name' => (($data['last_name'] ?? null) ?: $matchedUser?->last_name ?: null),
                    'dob' => (($data['dob'] ?? null) ?: $matchedUser?->dob ?: null),
                    'gender' => (($data['gender'] ?? null) ?: $matchedUser?->gender ?: null),
                    'email' => (($data['email'] ?? null) ?: $matchedUser?->email ?: null),
                    'phone' => (($data['phone'] ?? null) ?: $matchedUser?->phone ?: null),
                    'address_line_1' => (($data['address_line_1'] ?? null) ?: $matchedUser?->address1 ?: null),
                    'address_line_2' => (($data['address_line_2'] ?? null) ?: $matchedUser?->address2 ?: null),
                    'city' => (($data['city'] ?? null) ?: $matchedUser?->city ?: $matchedUser?->shipping_city ?: null),
                    'county' => (($data['county'] ?? null) ?: $matchedUser?->county ?: null),
                    'postcode' => (($data['postcode'] ?? null) ?: $matchedUser?->postcode ?: $matchedUser?->shipping_postcode ?: null),
                    'country' => (($data['country'] ?? null) ?: $matchedUser?->country ?: $matchedUser?->shipping_country ?: 'United Kingdom'),
                ]);

                $data['patient_id'] = $patient->id;
                $patientWasSelected = true;

                if ($matchedUser) {
                    $data['user_id'] = $matchedUser->id;
                }

                $data['first_name'] = (($data['first_name'] ?? null) ?: $matchedUser?->first_name ?: ($data['first_name'] ?? null));
                $data['last_name'] = (($data['last_name'] ?? null) ?: $matchedUser?->last_name ?: ($data['last_name'] ?? null));
                $data['dob'] = (($data['dob'] ?? null) ?: $matchedUser?->dob ?: ($data['dob'] ?? null));
                $data['gender'] = (($data['gender'] ?? null) ?: $matchedUser?->gender ?: ($data['gender'] ?? null));
                $data['email'] = (($data['email'] ?? null) ?: $matchedUser?->email ?: ($data['email'] ?? null));
                $data['phone'] = (($data['phone'] ?? null) ?: $matchedUser?->phone ?: ($data['phone'] ?? null));
                $data['address_line_1'] = (($data['address_line_1'] ?? null) ?: $matchedUser?->address1 ?: ($data['address_line_1'] ?? null));
                $data['address_line_2'] = (($data['address_line_2'] ?? null) ?: $matchedUser?->address2 ?: ($data['address_line_2'] ?? null));
                $data['city'] = (($data['city'] ?? null) ?: $matchedUser?->city ?: $matchedUser?->shipping_city ?: ($data['city'] ?? null));
                $data['county'] = (($data['county'] ?? null) ?: $matchedUser?->county ?: ($data['county'] ?? null));
                $data['postcode'] = (($data['postcode'] ?? null) ?: $matchedUser?->postcode ?: $matchedUser?->shipping_postcode ?: ($data['postcode'] ?? null));
                $data['country'] = (($data['country'] ?? null) ?: $matchedUser?->country ?: $matchedUser?->shipping_country ?: 'United Kingdom');
            }
        }

        $prefix = $patientWasOriginallySelected ? 'PWMR' : 'PWMN';
        $orderType = $prefix === 'PWMR' ? 'reorder' : 'new';

        $gender = $data['gender'] ?? null;
        if (is_string($gender) && trim($gender) !== '') {
            $g = strtolower(trim($gender));
            $data['gender'] = match ($g) {
                'male', 'm' => 'male',
                'female', 'f' => 'female',
                'other' => 'other',
                'prefer_not_to_say', 'prefer not to say', 'prefer-not-to-say' => 'prefer_not_to_say',
                default => null,
            };
        }

        $items = $data['items'] ?? [];
        if (is_array($items)) {
            $items = array_values(array_filter(array_map(function ($item) {
                if (! is_array($item)) {
                    return null;
                }

                return [
                    'name' => $item['name'] ?? null,
                    'variation' => $item['variation'] ?? null,
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                    'unit_price' => isset($item['unit_price']) && $item['unit_price'] !== '' ? (float) $item['unit_price'] : null,
                ];
            }, $items)));
        } else {
            $items = [];
        }

        $productsTotalMinor = 0;
        $metaItems = [];
        foreach ($items as $item) {
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $unitPrice = isset($item['unit_price']) && $item['unit_price'] !== null ? (float) $item['unit_price'] : 0;
            $unitMinor = (int) round($unitPrice * 100);
            $lineTotalMinor = $unitMinor * $qty;
            $productsTotalMinor += $lineTotalMinor;

            $metaItems[] = [
                'name' => $item['name'] ?? 'Item',
                'variation' => $item['variation'] ?? null,
                'variations' => $item['variation'] ?? null,
                'optionLabel' => $item['variation'] ?? null,
                'qty' => $qty,
                'quantity' => $qty,
                'unitMinor' => $unitMinor,
                'priceMinor' => $unitMinor,
                'lineTotalMinor' => $lineTotalMinor,
                'totalMinor' => $lineTotalMinor,
            ];
        }

        $serviceName = $data['service_name'] ?? null;
        $serviceSlug = trim((string) ($data['service_slug'] ?? ''));
        $serviceId = $data['service_id'] ?? null;

        if ($serviceId && SchemaFacade::hasTable('services')) {
            try {
                $serviceRow = DB::table('services')->where('id', $serviceId)->first();
                if ($serviceRow) {
                    $serviceName = isset($serviceRow->name) ? (string) $serviceRow->name : $serviceName;
                    $serviceSlug = isset($serviceRow->slug) && is_string($serviceRow->slug) && trim((string) $serviceRow->slug) !== ''
                        ? trim((string) $serviceRow->slug)
                        : $serviceSlug;
                }
            } catch (\Throwable) {
            }
        }

        if ($serviceSlug === '' && is_string($serviceName) && trim($serviceName) !== '') {
            $serviceSlug = Str::slug($serviceName);
        }

        $firstItem = is_array($metaItems[0] ?? null) ? $metaItems[0] : null;
        $selectedProductName = $firstItem['name'] ?? null;
        $selectedProductVariation = $firstItem['variation'] ?? $firstItem['variations'] ?? $firstItem['optionLabel'] ?? null;

        $reference = $this->generateReference($prefix);

        $meta = [
            'type' => $orderType,
            'source' => 'walk_in',
            'appointment_type' => 'walk_in',
            'is_walk_in' => true,
            'patient_id' => $data['patient_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'dob' => $data['dob'] ?? null,
            'gender' => $data['gender'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address_line_1' => $data['address_line_1'] ?? null,
            'address_line_2' => $data['address_line_2'] ?? null,
            'city' => $data['city'] ?? null,
            'county' => $data['county'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'country' => $data['country'] ?? 'United Kingdom',
            'service_id' => $data['service_id'] ?? null,
            'service' => $serviceName,
            'serviceName' => $serviceName,
            'service_slug' => $serviceSlug !== '' ? $serviceSlug : null,
            'consultation' => [
                'type' => $orderType,
                'mode' => $orderType,
            ],
            'selectedProduct' => [
                'name' => $selectedProductName,
                'variation' => $selectedProductVariation,
                'variations' => $selectedProductVariation,
            ],
            'appointment_at' => $data['appointment_at'] ?? null,
            'items' => $metaItems,
            'products_total_minor' => $productsTotalMinor,
            'payment_status' => 'Paid at pharmacy',
            'payment_status_label' => 'Paid at Pharmacy',
        ];

        if ($this->sourceAppointment) {
            $meta['manual_appointment_id'] = $this->sourceAppointment->getKey();
            $meta['manual_appointment_reference'] = $this->sourceAppointment->order_reference;
            if (filled($data['appointment_notes'] ?? null)) {
                $meta['admin_notes'] = 'Appointment notes: '.trim($data['appointment_notes']);
            }
        }

        $payload = [
            'reference' => $reference,
            'status' => 'pending',
            'booking_status' => 'pending',
            'payment_status' => 'Paid at pharmacy',
            'meta' => $meta,
        ];

        if (SchemaFacade::hasColumn('orders', 'user_id')) {
            $payload['user_id'] = $data['user_id'] ?? null;
        }

        if (SchemaFacade::hasColumn('orders', 'patient_id')) {
            $payload['patient_id'] = $data['patient_id'] ?? null;
        }

        if (SchemaFacade::hasColumn('orders', 'paid_at')) {
            $payload['paid_at'] = now();
        }

        return $payload;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $order = parent::handleRecordCreation($data);

        if ($this->sourceAppointment) {
            $this->sourceAppointment->forceFill([
                'order_id' => $order->getKey(),
                'order_reference' => $order->reference,
            ])->saveOrFail();
        }

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        if ($this->startConsultationAfterCreate) {
            try {
                return StartWalkInConsultation::start($this->getRecord());
            } catch (Throwable $exception) {
                report($exception);
                Notification::make()
                    ->warning()
                    ->title('The walk-in order was saved, but the consultation could not start')
                    ->body('Open the saved order from Walk Ins to try again.')
                    ->send();
            }
        }

        return static::getResource()::getUrl('index');
    }

    protected function generateReference(string $prefix): string
    {
        do {
            $reference = $prefix . random_int(100000, 999999);
        } while (Order::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
