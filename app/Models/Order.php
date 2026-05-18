<?php
// app/Models/Order.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Patient;

class Order extends Model
{
    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $table = 'orders';

    protected $fillable = [
        'reference',
        'status',
        'booking_status',
        'payment_status',
        'meta',
        'user_id',
        'patient_id',
        'paid_at',
    ];

    public function scopePendingNhs($q)
    {
        return $q
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"')) = ?", ['nhs'])
            ->where('status', 'pending');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function scopePendingApproval($q)
    {
        return $q->where('status', 'pending');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }

    public function scopeCompleted($q)
    {
        return $q->where('status', 'completed');
    }

    public function scopeRejected($q)
    {
        return $q->where('status', 'rejected');
    }

    public function scopeUnpaid($q)
    {
        return $q->where('status', 'unpaid');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function appointment()
    {
        // Temporary: still points to Booking model/table until Appointment model is fully adopted.
        return $this->hasOne(Booking::class, 'order_id');
    }

    /**
     * @deprecated Use appointment() instead.
     */
    public function booking()
    {
        return $this->appointment();
    }

    protected static function booted(): void
    {
        static::saving(function (self $order) {
            $status = strtolower((string) $order->status);
            if ($status === 'completed' && empty($order->completed_at)) {
                $order->completed_at = now();
            }
        });

        // Ensure meta contains user_id and patient_id on create
        static::creating(function (self $order) {
            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

            // Mirror user_id into meta.user_id if missing
            if (!empty($order->user_id) && ! data_get($meta, 'user_id')) {
                data_set($meta, 'user_id', $order->user_id);
            }

            // Work out patient_id (column if present, else infer from user->patient)
            $pid = null;
            if (\Schema::hasColumn($order->getTable(), 'patient_id')) {
                $pid = $order->getAttribute('patient_id');
            }
            if (empty($pid) && !empty($order->user_id)) {
                $pid = Patient::where('user_id', $order->user_id)->value('id');
                if ($pid && \Schema::hasColumn($order->getTable(), 'patient_id') && empty($order->getAttribute('patient_id'))) {
                    $order->setAttribute('patient_id', $pid);
                }
            }
            if (!empty($pid) && ! data_get($meta, 'patient_id')) {
                data_set($meta, 'patient_id', $pid);
            }

            $order->meta = $meta;
        });

        // Also enforce on update without clobbering existing values
        static::updating(function (self $order) {
            $meta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '[]', true) ?: []);

            if (!empty($order->user_id) && ! data_get($meta, 'user_id')) {
                data_set($meta, 'user_id', $order->user_id);
            }

            $pid = null;
            if (\Schema::hasColumn($order->getTable(), 'patient_id')) {
                $pid = $order->getAttribute('patient_id');
            }
            if (empty($pid) && !empty($order->user_id)) {
                $pid = Patient::where('user_id', $order->user_id)->value('id');
            }
            if (!empty($pid) && ! data_get($meta, 'patient_id')) {
                data_set($meta, 'patient_id', $pid);
            }

            $order->meta = $meta;
        });

        // Keep the appointments table in sync for paid/pending orders that already carry an appointment time in meta.
        // This is deliberately guarded so unpaid, completed, cancelled, rejected, or appointment-less orders are ignored.
        static::saved(function (self $order) {
            static::syncAppointmentFromMeta($order);
        });
    }

    public static function syncAppointmentFromMeta(self $order): void
    {
        try {
            if (! $order->id || ! $order->reference) {
                return;
            }

            if (! \Schema::hasTable('appointments')) {
                return;
            }

            $orderStatus = strtolower(trim((string) ($order->status ?? '')));
            $bookingStatus = strtolower(trim((string) ($order->booking_status ?? '')));
            $paymentStatus = strtolower(trim((string) ($order->payment_status ?? '')));

            $terminalStatuses = ['completed', 'complete', 'done', 'cancelled', 'canceled', 'rejected'];

            if (in_array($orderStatus, $terminalStatuses, true)) {
                return;
            }

            if (in_array($bookingStatus, $terminalStatuses, true)) {
                return;
            }

            if ($paymentStatus === 'unpaid') {
                return;
            }

            $meta = is_array($order->meta)
                ? $order->meta
                : (json_decode($order->meta ?? '[]', true) ?: []);

            if (! is_array($meta)) {
                return;
            }

            $appointmentAt = data_get($meta, 'appointment_at')
                ?? data_get($meta, 'appointment_start_at')
                ?? data_get($meta, 'appointment_start')
                ?? data_get($meta, 'appointment_datetime')
                ?? data_get($meta, 'appointmentDateTime');

            if (! $appointmentAt) {
                return;
            }

            $start = \Illuminate\Support\Carbon::parse($appointmentAt)->setTimezone('UTC');

            $email = $order->email
                ?? data_get($meta, 'email')
                ?? data_get($meta, 'patient.email')
                ?? data_get($meta, 'customer.email');

            $firstName = $order->first_name
                ?? data_get($meta, 'firstName')
                ?? data_get($meta, 'first_name')
                ?? data_get($meta, 'patient.first_name')
                ?? data_get($meta, 'customer.first_name');

            $lastName = $order->last_name
                ?? data_get($meta, 'lastName')
                ?? data_get($meta, 'last_name')
                ?? data_get($meta, 'patient.last_name')
                ?? data_get($meta, 'customer.last_name');

            $service = data_get($meta, 'service')
                ?? data_get($meta, 'service_name')
                ?? data_get($meta, 'service.name')
                ?? 'Weight Management';

            $serviceSlug = data_get($meta, 'service_slug')
                ?? data_get($meta, 'service.slug')
                ?? \Illuminate\Support\Str::slug((string) $service);

            $existing = \App\Models\Appointment::query()
                ->where(function ($query) use ($order) {
                    $query->where('order_id', $order->id)
                        ->orWhere('order_reference', $order->reference);
                })
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', '')
                        ->orWhereIn('status', ['pending', 'booked', 'approved', 'waiting']);
                })
                ->orderByDesc('id')
                ->first();

            $appointment = $existing ?: new \App\Models\Appointment();

            if (\Schema::hasColumn('appointments', 'order_id')) {
                $appointment->order_id = $order->id;
            }

            if (\Schema::hasColumn('appointments', 'order_reference')) {
                $appointment->order_reference = $order->reference;
            }

            $appointment->start_at = $start->format('Y-m-d H:i:s');

            if (\Schema::hasColumn('appointments', 'end_at')) {
                $appointment->end_at = $start->copy()->addMinutes(20)->format('Y-m-d H:i:s');
            }

            if (\Schema::hasColumn('appointments', 'status')) {
                $appointment->status = $appointment->status ?: 'pending';
            }

            if (\Schema::hasColumn('appointments', 'email') && $email) {
                $appointment->email = $email;
            }

            if (\Schema::hasColumn('appointments', 'first_name') && $firstName) {
                $appointment->first_name = $firstName;
            }

            if (\Schema::hasColumn('appointments', 'last_name') && $lastName) {
                $appointment->last_name = $lastName;
            }

            if (\Schema::hasColumn('appointments', 'service') && $service) {
                $appointment->service = $service;
            }

            if (\Schema::hasColumn('appointments', 'service_slug') && $serviceSlug) {
                $appointment->service_slug = $serviceSlug;
            }

            $appointment->save();

            // Keep only one active appointment attached to this order.
            \App\Models\Appointment::query()
                ->whereKeyNot($appointment->getKey())
                ->where(function ($query) use ($order) {
                    $query->where('order_id', $order->id)
                        ->orWhere('order_reference', $order->reference);
                })
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere('status', '')
                        ->orWhereIn('status', ['pending', 'booked', 'approved', 'waiting']);
                })
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $e) {
            \Log::warning('order.sync_appointment_from_meta_failed', [
                'order_id' => $order->id ?? null,
                'reference' => $order->reference ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Accessor for products_total_minor
     * Always returns an integer number of pence (minor units) so Filament never sees null.
     */
    public function getProductsTotalMinorAttribute(): int
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        // Preferred item arrays in various payload shapes
        $paths = [
            'meta.items',
            'meta.lines',
            'meta.products',
            'meta.line_items',
            'meta.cart.items',
        ];

        $sum = 0;

        $toMinor = function ($v): ?int {
            if ($v === null || $v === '') return null;
            if (is_int($v)) return $v;
            if (is_float($v)) return (int) round($v * 100);
            if (is_string($v)) {
                $clean = preg_replace('/[^\d\.\-]/', '', $v);
                if ($clean === '' || $clean === '-' || $clean === null) return null;
                if (str_contains($clean, '.')) return (int) round(((float) $clean) * 100);
                return (int) $clean;
            }
            return null;
        };

        // Build items collection from the first non-empty known path
        $items = collect(data_get(['meta' => $meta], 'meta.items'));
        if ($items->isEmpty()) {
            foreach ($paths as $p) {
                $arr = data_get(['meta' => $meta], $p);
                if (is_array($arr) && count($arr)) {
                    $items = collect($arr);
                    break;
                }
            }
        }

        foreach ($items as $it) {
            $line = $toMinor(data_get($it, 'totalMinor'));
            if ($line === null) {
                $unit = $toMinor(data_get($it, 'unitMinor'));
                $qty  = (int) (data_get($it, 'qty') ?? 1);
                if ($unit !== null) $line = $unit * max($qty, 1);
            }
            if ($line !== null) $sum += $line;
        }

        // Fallback totals if no line items matched
        $fallbacks = [
            'meta.totals.products_total_minor',
            'meta.totalMinor',
            'meta.subtotalMinor',
            'meta.totals.subtotalMinor',
        ];
        if ($sum === 0) {
            foreach ($fallbacks as $key) {
                $v = $toMinor(data_get(['meta' => $meta], $key));
                if ($v !== null) {
                    $sum = $v;
                    break;
                }
            }
        }

        return max(0, (int) $sum);
    }

    public function getScrVerifiedAttribute(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []);
        $val = data_get($meta, 'scr_verified') ?? data_get($meta, 'scr_status') ?? data_get($meta, 'scrVerified');

        if ($val !== null && $val !== '') {
            $s = strtolower(trim((string)$val));
            return in_array($s, ['y','yes','true','1'], true) ? 'Yes' : (in_array($s, ['n','no','false','0'], true) ? 'No' : '—');
        }

        $u = $this->user;
        if ($u && $u->scr_verified !== null) {
            return $u->scr_verified ? 'Yes' : 'No';
        }

        return '—';
    }
}