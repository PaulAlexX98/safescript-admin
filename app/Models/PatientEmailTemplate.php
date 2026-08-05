<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientEmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'subject',
        'message',
    ];
}
