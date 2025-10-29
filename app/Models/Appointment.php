<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'start_at', 'end_at',
        'patient_name', 'first_name', 'last_name',
        'service_slug', 'service_name',
        'status', 'order_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at'   => 'datetime',
    ];

    // Nice title used by Filament (Resource record title)
    public function getDisplayTitleAttribute(): string
    {
        $name = $this->patient_name ?: trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
        $when = optional($this->start_at)->format('d M Y, H:i');
        return trim(($name ?: 'Appointment').' · '.($when ?: ''));
    }
}