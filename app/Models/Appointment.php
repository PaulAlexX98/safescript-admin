<?php

// app/Models/Appointment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'dob' => 'date',
    ];

    public function scopeUpcoming($q)
    {
        return $q->where('status', 'booked')
                 ->where('start_at', '>=', now())
                 ->orderBy('start_at');
    }

    public function getAppointmentTimeAttribute(): string
    {
        $start = $this->start_at?->format('d-m-Y H:i') ?? '';
        $end = $this->end_at?->format('H:i') ?? '';
        return trim($start . ' - ' . $end, ' -');
    }

    // relation if you track items
    public function items()
    {
        return $this->hasMany(AppointmentItem::class, 'appointment_id');
    }
}