<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationFormResponse extends Model
{
    protected $fillable = [
        'consultation_session_id', 'clinic_form_id', 'form_type',
        'step_slug', 'service_slug', 'treatment_slug',
        'form_version', 'data', 'is_complete', 'completed_at',
        'patient_context', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'data' => 'array',
        'is_complete' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(ConsultationSession::class, 'consultation_session_id');
    }

    public function clinicForm()
    {
        return $this->belongsTo(ClinicForm::class);
    }
}