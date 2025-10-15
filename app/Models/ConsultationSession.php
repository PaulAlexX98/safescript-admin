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
        'order_id',
        'service',
        'treatment',
        'templates',
        'steps',
        'current',
    ];
    
    protected $casts = [
        'templates' => 'array',
        'steps'     => 'array'
    ];
}