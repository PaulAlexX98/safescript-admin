<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * App\Models\ApprovedOrder
 *
 * Mirrors the structure style of Appointment, but targets the "orders" table.
 * Scopes to approved orders and provides helpers for time and items.
 */
class ApprovedOrder extends Model
{
    /**
     * Use the orders table (same as the base Order model).
     */
    protected $table = 'orders';


    /**
     * Casts similar in spirit to Appointment, adapted for orders.
     */
    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function ($model) {
            // decode current + incoming meta
            $curr = is_array($model->getOriginal('meta'))
                ? $model->getOriginal('meta')
                : (json_decode($model->getOriginal('meta') ?? '[]', true) ?: []);

            $incoming = is_array($model->meta)
                ? $model->meta
                : (json_decode($model->meta ?? '[]', true) ?: []);

            // paths we NEVER allow to be lost
            $mustKeep = [
                'assessment.answers',
                'assessment_snapshot',
                'consultation_session_id',
            ];

            foreach ($mustKeep as $path) {
                if (!\Illuminate\Support\Arr::has($incoming, $path) &&
                    \Illuminate\Support\Arr::has($curr, $path)) {
                    \Illuminate\Support\Arr::set(
                        $incoming,
                        $path,
                        \Illuminate\Support\Arr::get($curr, $path)
                    );
                }
            }

            // final non-destructive merge (incoming wins, but preserved keys survive)
            $model->meta = array_replace_recursive($curr, $incoming);
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
     * Defaults to Pharmacist Advice as the first step.
     */
    public function getConsultationUrlAttribute(): ?string
    {
        // Try several common locations for the session id
        $sessionId = data_get($this->attributes, 'consultation_session_id')
            ?? data_get($this->meta, 'consultation_session_id')
            ?? data_get($this->meta, 'session.id')
            ?? data_get($this->meta, 'session_id')
            ?? null;

        if (!$sessionId) {
            return null;
        }

        return route('consultations.runner.pharmacist_advice', ['session' => $sessionId]);
    }
}
