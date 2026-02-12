<?php

namespace App\Models;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

use App\Models\User;

/**
 * App\Models\NhsPending
 *
 * Mirrors the structure style of Appointment, but targets the "orders" table.
 * Scopes to approved orders and provides helpers for time and items.
 */
class NhsPending extends Model
{
    /**
     * Use the orders table (same as the base Order model).
     */
    protected $table = 'orders';
    protected $fillable = [
        // your existing fillables
        'status',
        'completed_at',
        'rejected_at',
        'rejection_reason',
    ];

    /**
     * Casts similar in spirit to Appointment, adapted for orders.
     */
    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'completed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::retrieved(function ($model) {
            // This model is used for reading in the admin. NHS orders are created/updated elsewhere,
            // so we enrich in-memory on retrieval instead of relying on save hooks.
            try {
                $meta = is_array($model->meta) ? $model->meta : (json_decode($model->meta ?? '[]', true) ?: []);

                $email = data_get($meta, 'email')
                    ?: data_get($meta, 'patient.email')
                    ?: data_get($meta, 'user.email')
                    ?: data_get($meta, 'customer.email');
                $email = is_string($email) ? trim($email) : '';

                $u = null;

                // Prefer real relation when user_id exists
                if (!empty($model->user_id)) {
                    $u = User::query()->find($model->user_id);
                }

                // If we still don't have a user and meta doesn't carry email,
                // try to resolve from an Appointment linked to this order.
                if (!$u && $email === '' && class_exists('\\App\\Models\\Appointment')) {
                    try {
                        $appt = \App\Models\Appointment::query()
                            ->where('order_id', $model->getKey())
                            ->latest('id')
                            ->first();

                        if ($appt) {
                            // Prefer appointment.user_id
                            if (!empty($appt->user_id)) {
                                $u = User::query()->find($appt->user_id);
                                if ($u) {
                                    $model->setAttribute('user_id', $u->id);
                                    $model->setRelation('user', $u);
                                }
                            }

                            // Fallback: appointment email fields/meta
                            if (!$u) {
                                $apptEmail = '';
                                try {
                                    if (isset($appt->email) && is_string($appt->email)) {
                                        $apptEmail = trim($appt->email);
                                    }
                                } catch (\Throwable $e) {
                                    // ignore
                                }

                                if ($apptEmail === '') {
                                    try {
                                        $apptMeta = is_array($appt->meta) ? $appt->meta : (json_decode($appt->meta ?? '[]', true) ?: []);
                                        $apptEmail = (string) (
                                            data_get($apptMeta, 'email')
                                            ?: data_get($apptMeta, 'patient.email')
                                            ?: data_get($apptMeta, 'user.email')
                                            ?: ''
                                        );
                                        $apptEmail = trim($apptEmail);
                                    } catch (\Throwable $e) {
                                        // ignore
                                    }
                                }

                                if ($apptEmail !== '') {
                                    $email = $apptEmail;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }

                // Fallback: resolve user by email when user_id is missing
                if (!$u && $email !== '') {
                    $u = User::query()->where('email', $email)->first();
                    if ($u) {
                        // Attach the relation in-memory so `$record->user` works in Filament tables
                        $model->setAttribute('user_id', $u->id);
                        $model->setRelation('user', $u);
                    }
                }

                $setIfEmpty = function (string $path, $value) use (&$meta) {
                    if ($value === null) return;
                    $v = is_string($value) ? trim($value) : $value;
                    if ($v === '' || $v === []) return;
                    $existing = data_get($meta, $path);
                    $existing = is_string($existing) ? trim($existing) : $existing;
                    if ($existing === null || $existing === '' || $existing === []) {
                        data_set($meta, $path, $v);
                    }
                };

                if ($u) {
                    $setIfEmpty('first_name', $u->first_name ?? null);
                    $setIfEmpty('last_name', $u->last_name ?? null);
                    $setIfEmpty('dob', $u->dob ?? null);
                    $setIfEmpty('phone', $u->phone ?? null);
                    $setIfEmpty('email', $u->email ?? $email);

                    $setIfEmpty('address1', $u->address1 ?? null);
                    $setIfEmpty('address2', $u->address2 ?? null);
                    $setIfEmpty('city', $u->city ?? null);
                    $setIfEmpty('postcode', $u->postcode ?? null);
                    $setIfEmpty('country', $u->country ?? null);

                    // Keep a patient namespace too
                    $setIfEmpty('patient.first_name', $u->first_name ?? null);
                    $setIfEmpty('patient.last_name', $u->last_name ?? null);
                    $setIfEmpty('patient.dob', $u->dob ?? null);
                    $setIfEmpty('patient.phone', $u->phone ?? null);
                    $setIfEmpty('patient.email', $u->email ?? $email);
                    $setIfEmpty('patient.address1', $u->address1 ?? null);
                    $setIfEmpty('patient.address2', $u->address2 ?? null);
                    $setIfEmpty('patient.city', $u->city ?? null);
                    $setIfEmpty('patient.postcode', $u->postcode ?? null);
                    $setIfEmpty('patient.country', $u->country ?? null);
                } else {
                    if ($email !== '') {
                        $setIfEmpty('email', $email);
                        $setIfEmpty('patient.email', $email);
                    }
                }

                // PNHS is always Free
                $ref = (string) ($model->reference ?? '');
                if ($ref !== '' && Str::startsWith(Str::upper($ref), 'PNHS')) {
                    data_set($meta, 'payment_status', 'free');
                    data_set($meta, 'payment_status_label', 'Free');
                    data_set($meta, 'paid', false);
                    data_set($meta, 'total_minor', 0);
                }

                $model->meta = $meta;
            } catch (\Throwable $e) {
                // never block read
            }
        });
    }

    /**
     * Scope to approved orders, similar to Appointment::scopeUpcoming().
     */
    public function scopeApproved(Builder $q): Builder
    {
        return $q->whereRaw("LOWER(booking_status) = 'approved'")
                 ->orderByDesc('id');
    }

    /**
     * Accessor similar to Appointment::getAppointmentTimeAttribute().
     * Returns a formatted time range from the order if present.
     */
    public function getMetaAttribute($value)
    {
        // decode meta to array
        $meta = is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);

        // Ensure PNHS is always Free (safety net)
        try {
            $ref = (string) ($this->reference ?? '');
            if ($ref !== '' && Str::startsWith(Str::upper($ref), 'PNHS')) {
                data_set($meta, 'payment_status', 'free');
                data_set($meta, 'payment_status_label', 'Free');
                data_set($meta, 'paid', false);
                data_set($meta, 'total_minor', 0);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // helper get nested by dot path
        $get = function ($arr, $path) {
            return data_get($arr, $path);
        };

        // candidate containers for items
        $containers = ['products', 'items', 'lines', 'line_items', 'cart.items'];
        $containerKey = null;
        $items = null;
        foreach ($containers as $c) {
            $v = $get($meta, $c);
            if ($v !== null) {
                $containerKey = $c;
                $items = $v;
                break;
            }
        }

        // If items is JSON string, decode
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items = $decoded;
            }
        }

        // If single associative item, wrap as list
        if (is_array($items) && !empty($items)) {
            $isList = array_keys($items) === range(0, count($items) - 1);
            if (!$isList) {
                $items = [$items];
            }
        } elseif (!is_array($items)) {
            $items = [];
        }

        // resolver for variation using only structured keys and arrays
        $resolveVar = function ($row) {
            $keys = [
                // flat common keys
                'variations','variation','optionLabel','variant','dose','strength','option',
                'label','text','title','display','displayName','fullLabel','full_option_label','option_text',
                'plan','package','bundle','pack','size','volume','strength_text',
                // nested on the row
                'meta.variations','meta.variation','meta.optionLabel','meta.variant','meta.dose','meta.strength','meta.option',
                'meta.label','meta.text','meta.title','meta.display','meta.displayName','meta.fullLabel','meta.full_option_label','meta.option_text',
                'meta.plan','meta.package','meta.bundle','meta.pack','meta.size','meta.volume','meta.strength_text',
                // selected nested object on the row
                'selected.variations','selected.variation','selected.optionLabel','selected.variant','selected.dose','selected.strength','selected.option',
                'selected.label','selected.text','selected.title','selected.display','selected.displayName','selected.fullLabel','selected.full_option_label','selected.option_text',
                'selected.plan','selected.package','selected.bundle','selected.pack','selected.size','selected.volume','selected.strength_text',
            ];
            foreach ($keys as $k) {
                $v = data_get($row, $k);
                if ($v !== null && $v !== '') {
                    if (is_array($v)) {
                        // favor label then value otherwise join scalars
                        if (array_key_exists('label', $v)) return (string) $v['label'];
                        if (array_key_exists('value', $v)) return (string) $v['value'];
                        $flat = [];
                        foreach ($v as $vv) {
                            if (is_array($vv)) {
                                if (isset($vv['label'])) $flat[] = (string) $vv['label'];
                                elseif (isset($vv['value'])) $flat[] = (string) $vv['value'];
                            } else {
                                $flat[] = (string) $vv;
                            }
                        }
                        $joined = trim(implode(' ', array_filter($flat, fn($s) => $s !== '')));
                        if ($joined !== '') return $joined;
                        continue;
                    }
                    return (string) $v;
                }
            }
            // arrays of options or attributes on the row
            if (is_array($row['options'] ?? null)) {
                $parts = [];
                foreach ($row['options'] as $op) {
                    if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                }
                $joined = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                if ($joined !== '') return $joined;
            }
            if (is_array($row['attributes'] ?? null)) {
                $parts = [];
                foreach ($row['attributes'] as $op) {
                    if (is_array($op)) $parts[] = $op['label'] ?? $op['value'] ?? null;
                }
                $joined = trim(implode(' ', array_filter(array_map('strval', array_filter($parts)))));
                if ($joined !== '') return $joined;
            }
            return '';
        };

        // normalize each item and ensure variations populated
        foreach ($items as $i => $row) {
            if (!is_array($row)) continue;
            $var = $resolveVar($row);
            if (($var === '' || $var === null)) {
                // for single item orders try meta selectedProduct and fallbacks
                $singleKeys = [
                    'selectedProduct.variations','selected_product.variations',
                    'selectedProduct.variation','selected_product.variation',
                    'selectedProduct.optionLabel','selected_product.optionLabel',
                    'selectedProduct.variant','selected_product.variant',
                    'selectedProduct.dose','selected_product.dose',
                    'selectedProduct.strength','selected_product.strength',
                    'selectedProduct.label','selected_product.label',
                    'selectedProduct.text','selected_product.text',
                    'selectedProduct.fullLabel','selected_product.fullLabel',
                    'selectedProduct.full_option_label','selected_product.full_option_label',
                    'selectedProduct.option_text','selected_product.option_text',
                    'variant','dose','strength','label','text','title','display','displayName','fullLabel','full_option_label','option_text','variation','option',
                ];
                foreach ($singleKeys as $k) {
                    $try = $get($meta, $k);
                    if ($try !== null && $try !== '') {
                        if (is_array($try)) {
                            if (isset($try['label'])) { $var = (string) $try['label']; break; }
                            if (isset($try['value'])) { $var = (string) $try['value']; break; }
                            $joined = trim(implode(' ', array_map('strval', $try)));
                            if ($joined !== '') { $var = $joined; break; }
                        } else {
                            $var = (string) $try; break;
                        }
                    }
                }
            }
            if ($var !== '' && $var !== null) {
                $row['variations'] = $row['variations'] ?? $var;
                $row['variation']  = $row['variation']  ?? $var;
            }
            $items[$i] = $row;
        }

        // write items back to meta under the detected container key
        if ($containerKey !== null) {
            data_set($meta, $containerKey, $items);
        }

        return $meta;
    }

    public function getAppointmentTimeAttribute(): string
    {
        $start = optional($this->order_start_at)->format('d-m-Y H:i') ?? '';
        $end   = optional($this->order_end_at)->format('H:i') ?? '';
        return trim(($start ? $start : '') . ($end ? ' — ' . $end : ''), ' —');
    }

    public function getProductsTotalMinorAttribute(): int
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        // try explicit order totals first
        foreach (['totalMinor','amountMinor','total_minor','amount_minor'] as $k) {
            $v = data_get($meta, $k);
            if (is_numeric($v)) return max(0, (int) $v);
        }

        $paths = [
            'items',
            'lines',
            'products',
            'line_items',
            'cart.items',
        ];

        $toMinor = function ($v): ?int {
            if ($v === null || $v === '') return null;
            if (is_int($v)) return $v;
            if (is_float($v)) return (int) round($v * 100);
            if (is_string($v)) {
                $s = preg_replace('/[^\d\.\,\-]/', '', trim($v));
                if ($s === '') return null;
                if (str_contains($s, ',') && !str_contains($s, '.')) $s = str_replace(',', '.', $s);
                else $s = str_replace(',', '', $s);
                return is_numeric($s) ? (int) round(((float) $s) * 100) : null;
            }
            return null;
        };

        $sum = 0;

        foreach ($paths as $p) {
            $arr = data_get($meta, $p);
            if (!is_array($arr) || $arr === []) continue;

            // wrap single associative item
            $isList = array_keys($arr) === range(0, count($arr) - 1);
            if (!$isList && (isset($arr['name']) || isset($arr['title']) || isset($arr['product_name']))) {
                $arr = [$arr];
            }

            foreach ($arr as $it) {
                if (!is_array($it)) continue;
                $qty = max(1, (int) ($it['qty'] ?? $it['quantity'] ?? 1));

                // prefer line total
                $picked = null;
                foreach (['lineTotalMinor','totalMinor','amountMinor','subtotalMinor','priceMinor','minor','pennies'] as $k) {
                    if (array_key_exists($k, $it) && $it[$k] !== null && $it[$k] !== '') {
                        $picked = $toMinor($it[$k]);
                        if ($picked !== null) break;
                    }
                }

                // fall back to unit price times qty
                if ($picked === null) {
                    foreach (['unitMinor','unitPriceMinor'] as $k) {
                        if (array_key_exists($k, $it) && $it[$k] !== null && $it[$k] !== '') {
                            $u = $toMinor($it[$k]);
                            if ($u !== null) { $picked = $u * $qty; break; }
                        }
                    }
                }

                if ($picked !== null) $sum += (int) $picked;
            }
        }

        // last resort fallbacks
        if ($sum === 0) {
            foreach (['totals.products_total_minor','subtotalMinor','totals.subtotalMinor'] as $k) {
                $v = $toMinor(data_get($meta, $k));
                if ($v !== null) { $sum = $v; break; }
            }
        }

        return max(0, (int) $sum);
    }

    /**
     * Relation to user who placed the order.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Optional: expose items similar to Appointment::items().
     * For orders, items are commonly stored in meta; this returns an array.
     */
    public function getItemsAttribute(): array
    {
        $m = $this->meta ?: [];
        foreach (['items','products','lines','line_items'] as $key) {
            $val = data_get($m, $key);
            if (is_array($val) && !empty($val)) {
                return $val;
            }
        }
        return [];
    }

    /**
     * Direct URL to start or continue the consultation on the new split pages.
     * Chooses correct step and type for the desired consultation flow.
     */
    public function getConsultationUrlAttribute(): ?string
    {
        // session id from attributes or meta
        $sessionId = data_get($this->attributes, 'consultation_session_id')
            ?? data_get($this->meta, 'consultation_session_id')
            ?? data_get($this->meta, 'session.id')
            ?? data_get($this->meta, 'session_id')
            ?? null;

        if (!$sessionId) {
            return null;
        }

        // decide type then step
        $meta = is_array($this->meta) ? $this->meta : [];
        $desiredType = $this->resolveDesiredConsultationType($meta);
        $isReorder = $desiredType === 'reorder' ? true : $this->detectReorderFromMeta($meta);
        $step = $isReorder ? 'reorder' : 'risk-assessment';

        // candidate named routes for each step
        $nameSets = [
            'reorder' => [
                'consultations.reorder',
                'consultations.runner.reorder',
            ],
            'risk-assessment' => [
                'consultations.risk_assessment',
                'consultations.risk-assessment',
                'consultations.runner.risk_assessment',
                'consultations.runner.risk-assessment',
            ],
        ];

        foreach ($nameSets[$step] as $name) {
            if (\Illuminate\Support\Facades\Route::has($name)) {
                // Pass along type to keep downstream resolvers deterministic
                return route($name, ['session' => $sessionId, 'type' => $desiredType]);
            }
        }

        // final fallback
        return url("/admin/consultations/{$sessionId}/{$step}");
    }

    /**
     * Accessor: resolved consultation type for this order.
     */
    public function getConsultationTypeAttribute(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        return $this->resolveDesiredConsultationType($meta);
    }

    /**
     * Resolve the desired consultation type from request hints and meta.
     * Returns one of: reorder, nhs, new, risk_assessment
     */
    private function resolveDesiredConsultationType(array $meta): string
    {
        // request query hints if present
        $rq = function (string $key): ?string {
            try {
                $v = request()->query($key);
                return is_string($v) ? strtolower($v) : null;
            } catch (\Throwable $e) {
                return null; // not in HTTP context
            }
        };

        $candidates = array_filter([
            $rq('type') ?: $rq('mode'),
            strtolower((string) data_get($meta, 'consultation.type')),
            strtolower((string) data_get($meta, 'consultation.mode')),
            strtolower((string) data_get($meta, 'type')),
            strtolower((string) data_get($meta, 'flow')),
            strtolower((string) data_get($meta, 'order_type')),
        ], fn ($v) => is_string($v) && $v !== '');

        foreach ($candidates as $c) {
         
            if ($c === 'nhs') return 'nhs';
          
            if (in_array($c, ['risk_assessment', 'risk-assessment', 'raf'], true)) return 'risk_assessment';
        }

        // Heuristic: if meta smells like reorder, prefer it
        if ($this->detectReorderFromMeta($meta)) return 'reorder';

        return 'risk_assessment';
    }

    /**
     * Detects whether this order represents a reorder or repeat flow.
     */
    private function detectReorderFromMeta(array $meta): bool
    {
        // boolean style hints
        foreach ([
            'is_reorder',
            'isReorder',
            'flags.reorder',
            'reorder',
        ] as $path) {
            $v = data_get($meta, $path);
            if (is_bool($v)) return $v === true;
            if (is_numeric($v)) return ((int) $v) === 1;
            if (is_string($v) && $v !== '') {
                $sv = \Illuminate\Support\Str::slug($v);
                if (in_array($sv, ['1','true','yes','reorder','repeat','refill'], true)) return true;
            }
        }

        // string style type or flow
        foreach ([
            'consultation.type',
            'consultation.mode',
            'type',
            'mode',
            'flow',
            'order_type',
        ] as $path) {
            $v = data_get($meta, $path);
            if (!is_string($v) || $v === '') continue;
            $sv = \Illuminate\Support\Str::slug($v);
            if (str_contains($sv, 'reorder') ||
                str_contains($sv, 'repeat') ||
                str_contains($sv, 'refill')) {
                return true;
            }
        }

        // product plan hints
        foreach ([
            'selectedProduct.plan',
            'selected_product.plan',
            'selected.plan',
            'plan',
        ] as $path) {
            $v = data_get($meta, $path);
            if (!is_string($v) || $v === '') continue;
            $sv = \Illuminate\Support\Str::slug($v);
            if (str_contains($sv, 'repeat') ||
                str_contains($sv, 'refill') ||
                str_contains($sv, 'reorder')) {
                return true;
            }
        }

        return false;
    }
}