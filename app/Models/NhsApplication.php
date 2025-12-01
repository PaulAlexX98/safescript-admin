<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NhsApplication extends Model
{
    // Point to the API DB if needed
    // protected $connection = 'mysql_api';

    protected $table = 'nhs_applications';
    protected $guarded = [];

    protected $casts = [
        'dob' => 'date',
        'exemption_expiry' => 'date',
        'approved_at' => 'datetime',
        'use_alt_delivery' => 'boolean',
        'consent_patient' => 'boolean',
        'consent_nomination' => 'boolean',
        'consent_nomination_explained' => 'boolean',
        'consent_exemption_signed' => 'boolean',
        'consent_scr_access' => 'boolean',
        'meta' => 'array',
    ];
}