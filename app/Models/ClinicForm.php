<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Stores reusable clinic form definitions
 * schema holds the Builder blocks array
 * Use pickBest to resolve the most specific active form
 */
class ClinicForm extends Model
{
    protected $attributes = [
        'schema'    => '[]',
        'version'   => 1,
        'is_active' => true,
    ];
    protected $fillable = [
        'name',
        'description',
        'schema',
        'form_type',
        'service_slug',
        'treatment_slug',
        'version',
        'is_active',
    ];

    protected $casts = [
        'meta'   => 'array',
        'is_active' => 'boolean',
        'version'   => 'integer',
    ];

    /* ---------------------------
     | Scopes
     |----------------------------*/

    
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeForService(Builder $q, ?string $service): Builder
    {
        $service = trim((string) $service);
        return $service !== '' ? $q->where('service_slug', Str::slug($service)) : $q;
    }

    public function scopeForTreatment(Builder $q, ?string $treatment): Builder
    {
        $treatment = trim((string) $treatment);
        return $treatment !== '' ? $q->where('treatment_slug', Str::slug($treatment)) : $q;
    }

    public function scopeGlobal(Builder $q): Builder
    {
        return $q->whereNull('service_slug')->whereNull('treatment_slug');
    }

    public function scopeOfType(Builder $q, string $type): Builder
    {
        return $q->where('form_type', $type);
    }

    /* ---------------------------
     | Mutators (keep slugs clean)
     |----------------------------*/
    public function setServiceSlugAttribute($value): void
    {
        $this->attributes['service_slug'] = $value !== null ? Str::slug((string) $value) : null;
    }

    public function setTreatmentSlugAttribute($value): void
    {
        $this->attributes['treatment_slug'] = $value !== null ? Str::slug((string) $value) : null;
    }

    public function getSchemaAttribute($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \stdClass) {
            return (array) $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }

        return [];
    }

    public function setSchemaAttribute($value): void
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $this->attributes['schema'] = json_encode($value);
            return;
        }

        if (is_string($value)) {
            $this->attributes['schema'] = $value;
            return;
        }

        if ($value instanceof \JsonSerializable) {
            $this->attributes['schema'] = json_encode($value);
            return;
        }

        $this->attributes['schema'] = json_encode([]);
    }

    /* ---------------------------
     | Helpers
     |----------------------------*/

    public static function pickBest(string $type, ?string $serviceSlug = null, ?string $treatmentSlug = null): ?self
    {
        // 1. Treatment-specific
        if ($treatmentSlug) {
            $match = static::query()
                ->active()
                ->ofType($type)
                ->where('service_slug', Str::slug((string) $serviceSlug))
                ->where('treatment_slug', Str::slug((string) $treatmentSlug))
                ->orderByDesc('version')
                ->first();
            if ($match) {
                return $match;
            }
        }

        // 2. Service-specific (no treatment)
        if ($serviceSlug) {
            $match = static::query()
                ->active()
                ->ofType($type)
                ->where('service_slug', Str::slug((string) $serviceSlug))
                ->whereNull('treatment_slug')
                ->orderByDesc('version')
                ->first();
            if ($match) {
                return $match;
            }
        }

        // 3. Global fallback
        return static::query()
            ->active()
            ->ofType($type)
            ->whereNull('service_slug')
            ->whereNull('treatment_slug')
            ->orderByDesc('version')
            ->first();
    }

    public function blocksCount(): int
    {
        return is_array($this->schema) ? count($this->schema) : 0;
    }
}
