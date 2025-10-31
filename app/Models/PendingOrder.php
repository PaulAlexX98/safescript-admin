<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingOrder extends Model
{
    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $table = 'orders';

    public function getReferenceAttribute(): ?string
    {
        // Prefer real DB columns if present
        foreach (['reference','ref'] as $col) {
            if (isset($this->attributes[$col]) && is_string($this->attributes[$col])) {
                $v = trim($this->attributes[$col]);
                if ($v !== '') return $v;
            }
        }

        // Fallback to meta keys
        try { $m = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []); } catch (\Throwable) { $m = []; }
        $ref = data_get($m, 'ref') ?? data_get($m, 'reference');
        return is_string($ref) && trim($ref) !== '' ? trim($ref) : null;
    }

    /**
     * Normalize meta on read:
     * - Ensure products/items/lines are always a list of items (wrap single associative item)
     * - Populate a consistent 'variations' key on each item from structured fields only
     * - For single-item orders, if item lacks variation, pull from common structured meta paths
     */
    public function getMetaAttribute($value)
    {
        // decode meta to array
        $meta = is_array($value) ? $value : (json_decode($value ?? '[]', true) ?: []);

        // helper: get nested by dot path
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
                        // favor label then value, otherwise join scalars
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

        // normalize each item and ensure 'variations' populated from structured data
        foreach ($items as $i => $row) {
            if (!is_array($row)) continue;
            $var = $resolveVar($row);
            if (($var === '' || $var === null)) {
                // for single-item orders, try meta selectedProduct and fallbacks
                // this reads structured keys only from meta
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
                // write to a consistent key the resource already reads
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

    public function getAppointmentDatetimeAttribute(): string
    {
        try { $m = is_array($this->meta) ? $this->meta : (json_decode($this->meta ?? '[]', true) ?: []); } catch (\Throwable) { $m = []; }
        $s = data_get($m, 'appointment_start_at')
            ?? data_get($m, 'appointment.start_at')
            ?? data_get($m, 'appointment_at');
        $e = data_get($m, 'appointment_end_at')
            ?? data_get($m, 'appointment.end_at');

        if ($s) return $this->formatAppt($s, $e);

        if (!empty($this->reference)) {
            try {
                $om = \App\Models\Order::where('reference', $this->reference)->value('meta');
                $om = is_array($om) ? $om : (json_decode($om ?? '[]', true) ?: []);
                $s = data_get($om, 'appointment_start_at')
                    ?? data_get($om, 'appointment.start_at')
                    ?? data_get($om, 'appointment_at');
                $e = data_get($om, 'appointment_end_at')
                    ?? data_get($om, 'appointment.end_at');
                if ($s) return $this->formatAppt($s, $e);
            } catch (\Throwable) {}
        }

        return '—';
    }

    private function toLondonDateTime($val): ?\DateTimeImmutable
    {
        if ($val instanceof \DateTimeInterface) {
            try {
                return \DateTimeImmutable::createFromInterface($val)->setTimezone(new \DateTimeZone('Europe/London'));
            } catch (\Throwable) { return null; }
        }
        if ($val === null) return null;
        $s = trim((string) $val);
        if ($s === '') return null;
        $tzAware = (bool) preg_match('/(Z|[+\-]\d{2}:?\d{2})$/', $s);
        try {
            if ($tzAware) {
                $dt = new \DateTimeImmutable($s);
                return $dt->setTimezone(new \DateTimeZone('Europe/London'));
            } else {
                // treat naive strings as already in Europe/London
                return new \DateTimeImmutable($s, new \DateTimeZone('Europe/London'));
            }
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatAppt($start, $end = null): string
    {
        $sd = $this->toLondonDateTime($start);
        if (!$sd) return '—';
        $ed = $this->toLondonDateTime($end);
        if ($ed) {
            return $sd->format('Y-m-d') === $ed->format('Y-m-d')
                ? $sd->format('d M Y, H:i') . ' — ' . $ed->format('H:i')
                : $sd->format('d M Y, H:i') . ' — ' . $ed->format('d M Y, H:i');
        }
        return $sd->format('d M Y, H:i');
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

    public function scopePendingNhs($q)
    {
        return $q
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.\"type\"')) = ?", ['nhs'])
            ->where('status', 'pending');
    }

    public function scopePendingApproval($q)
    {
        return $q->where('booking_status', 'pending');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function booking()
    {
        return $this->hasOne(Booking::class, 'order_id');
    }
}
