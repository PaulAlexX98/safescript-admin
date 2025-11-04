<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ConsultationFormResponse extends Model
{
    /**
     * Explicit table name to avoid any pluralization mismatches.
     */
    protected $table = 'consultation_form_responses';

    /**
     * Default attributes.
     */
    protected $attributes = [
        'is_complete' => false,
    ];

    /**
     * Touch parent session updated_at when this model changes.
     */
    protected $touches = ['session'];

    protected $fillable = [
        'consultation_session_id', 'clinic_form_id', 'form_type',
        'step_slug', 'service_slug', 'treatment_slug',
        'form_version', 'data', 'is_complete', 'completed_at',
        'patient_context', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'data' => 'array',
        'patient_context' => 'array',
        'is_complete' => 'boolean',
        'completed_at' => 'datetime',
    ];

    protected $guarded = [];

    public function session()
    {
        return $this->belongsTo(ConsultationSession::class, 'consultation_session_id');
    }

    public function clinicForm()
    {
        return $this->belongsTo(ClinicForm::class);
    }

    /**
     * Scope helper to target a specific session + form pair.
     */
    public function scopeFor(Builder $query, int|string $sessionId, string $formType, ?int $clinicFormId = null): Builder
    {
        $query->where('consultation_session_id', $sessionId)
              ->where('form_type', $formType);

        if (! is_null($clinicFormId)) {
            $query->where('clinic_form_id', $clinicFormId);
        }

        return $query;
    }

    /**
     * Upsert a response payload for a session + form.
     */
    public static function upsertFor(
        int|string $sessionId,
        string $formType,
        array $data,
        ?int $clinicFormId = null,
        ?string $serviceSlug = null,
        ?string $treatmentSlug = null,
        ?int $formVersion = null,
        ?array $patientContext = null,
        ?int $userId = null
    ): self {
        // Match the DB unique index which is typically (consultation_session_id, form_type)
        $model = static::firstOrNew([
            'consultation_session_id' => $sessionId,
            'form_type' => $formType,
        ]);

        // Set or update attributes explicitly to avoid mass-assignment issues
        $model->clinic_form_id  = $clinicFormId;
        $model->service_slug    = $serviceSlug;
        $model->treatment_slug  = $treatmentSlug;
        $model->form_version    = $formVersion;
        $model->patient_context = $patientContext;
        $model->updated_by      = $userId;
        if (! $model->exists && empty($model->created_by)) {
            $model->created_by = $userId;
        }

        // Critical - persist the payload
        $model->data = $data;
        $model->save();

        return $model;
    }
}