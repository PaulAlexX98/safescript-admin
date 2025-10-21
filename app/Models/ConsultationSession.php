<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationSession extends Model
{
    public function order()
    {
        return $this->belongsTo(\App\Models\ApprovedOrder::class, 'order_id');
    }

    protected $fillable = [
        'user_id',
        'order_id',
        'service',
        'treatment',
        'templates',
        'steps',
        'current',
        'form_id',
        'form_type',
        'answers',
    ];
    
    protected $casts = [
        'templates' => 'array',
        'steps'     => 'array',
        'answers'   => 'array'
    ];
}