<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NhsPatient extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'first_name','last_name','dob','gender','nhs_number','email','phone',
        'address','address1','address2','city','postcode','country',
        'use_alt_delivery','delivery_address','delivery_address1','delivery_address2',
        'delivery_city','delivery_postcode','delivery_country',
        'exemption','exemption_number','exemption_expiry',
        'consent_patient','consent_nomination','consent_nomination_explained',
        'consent_exemption_signed','consent_scr_access',
        'meta',
    ];

    protected $casts = [
        'dob' => 'date',
        'exemption_expiry' => 'date',
        'use_alt_delivery' => 'boolean',
        'consent_patient' => 'boolean',
        'consent_nomination' => 'boolean',
        'consent_nomination_explained' => 'boolean',
        'consent_exemption_signed' => 'boolean',
        'consent_scr_access' => 'boolean',
        'meta' => 'array',
    ];

    public function application() {
        return $this->belongsTo(NhsApplication::class, 'application_id');
    }
}