<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';

    protected $fillable = [
        'order_id',
        'appointment_name',
        'appointment_start_at',
        'appointment_end_at',
        'patient_first_name',
        'patient_last_name',
        'dob',
        'status',
        'notes',
        'appointment_type',
    ];

    protected $casts = [
        'appointment_start_at' => 'datetime',
        'appointment_end_at'   => 'datetime',
        'dob'                  => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}