<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;


class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name','last_name','gender','phone','dob',
        'address1','address2',
        'shipping_address1',
        'shipping_address2',
        'shipping_city',
        'shipping_postcode',
        'shipping_country',
        'city','county','postcode','country',
        'marketing',
        'name','email','password',
        'is_pharmacist',
        'pharmacist_display_name',
        'gphc_number',
        'signature_path',
        'consultation_defaults',
        'id_verified',
        'id_verified_at',

    ];

    protected $hidden = ['password','remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'dob'                  => 'date',
            'password'             => 'hashed',   // ← make sure password is hashed
            'consultation_defaults'=> 'array',
            'is_pharmacist'        => 'boolean',
            'scr_verified' => 'boolean',
            'scr_verified_at' => 'datetime',
            'id_verified' => 'boolean',
            'id_verified_at' => 'datetime',
            'consultation_notes' => 'array',

        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return (bool) $this->is_staff;
        }

        return true;
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    // Easy URL for the signature preview/use in PDFs
    public function getSignatureUrlAttribute(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }

        // If already a data URL, return as-is
        if (is_string($this->signature_path) && str_starts_with($this->signature_path, 'data:image')) {
            return $this->signature_path;
        }

        return \Storage::disk('public')->url($this->signature_path);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }
}