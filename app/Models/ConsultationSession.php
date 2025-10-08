<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationSession extends Model
{
    protected $fillable = [
        'order_id',
        'patient_id',
        'service_slug',
        'treatment_slug',
        'status',
        'current_step',
        'step_keys',
        'template_snapshot',
        'answers',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'step_keys'         => 'array',
        'template_snapshot' => 'array',
        'answers'           => 'array',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
    ];
}