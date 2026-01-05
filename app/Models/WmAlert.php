<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WmAlert extends Model
{
    protected $table = 'wm_alerts';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];
}