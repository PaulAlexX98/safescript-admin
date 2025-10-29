<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'name','service_slug','timezone','slot_minutes','capacity','week','overrides',
    ];
    protected $casts = [
        'week' => 'array',
        'overrides' => 'array',
    ];
}
