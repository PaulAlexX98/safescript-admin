<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ClinicForm extends Model
{
    protected $fillable = [
        'name',
        'description',
        'schema',
        'form_type',
        'service_slug',
        'treatment_slug',
        'version',
        'is_active',
        'raf_schema',
        'raf_version',
        'raf_status',
    ];

    protected $casts = [
        'schema'    => 'array',
        'is_active' => 'boolean',
        'version'   => 'integer',
        'raf_version' => 'integer',
        'raf_schema' => 'array',
        'form_type' => 'string',
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
        return $service ? $q->where('service_slug', Str::slug($service)) : $q;
    }

    public function scopeForTreatment(Builder $q, ?string $treatment): Builder
    {
        return $treatment ? $q->where('treatment_slug', Str::slug($treatment)) : $q;
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

    /* ---------------------------
     | Helpers
     |----------------------------*/
    public function blocksCount(): int
    {
        return is_array($this->schema) ? count($this->schema) : 0;
    }
}

