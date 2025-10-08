<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    protected $table = 'users'; // map Patient model to users table

    protected $fillable = [
    'user_id','internal_id','first_name','last_name','email','phone',
    'dob','gender','street','city','postcode','country','is_active',
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    /**
     * Computed internal code like 0001, 0002 ... (not stored in DB).
     */
    public function getInternalIdAttribute(): string
    {
        return str_pad((string) ($this->id ?? 0), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Convenience accessor for full name (used by record title).
     */
    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    /**
     * Orders placed by this user (patient).
     */
    public function orders()
    {
        return $this->hasMany(\App\Models\Order::class, 'user_id');
    }

    /**
     * Bookings associated via orders.
     */
    public function bookings()
    {
        return $this->hasManyThrough(
            \App\Models\Booking::class, // Final related
            \App\Models\Order::class,   // Through
            'user_id',  // Foreign key on orders table...
            'order_id', // Foreign key on bookings table...
            'id',       // Local key on users table
            'id'        // Local key on orders table
        );
    }
}
