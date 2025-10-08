<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicForm extends Model
{
    protected $fillable = ['name', 'description', 'visibility', 'schema'];
    protected $casts = [
        'schema' => 'array',
    ];
}
