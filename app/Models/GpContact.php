<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GpContact extends Model
{
    protected $fillable = [
        'gp_address',
        'normalized_address',
        'address_hash',
        'email',
        'assigned_by',
    ];

    public static function normalizeAddress(?string $address): string
    {
        return Str::of((string) $address)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish()
            ->toString();
    }

    public static function hashAddress(?string $address): string
    {
        return hash('sha256', static::normalizeAddress($address));
    }

    public static function emailForAddress(?string $address): ?string
    {
        if (blank($address)) {
            return null;
        }

        return static::query()
            ->where('address_hash', static::hashAddress($address))
            ->value('email');
    }
}
