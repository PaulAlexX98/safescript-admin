<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'name','slug','description','status','view_type','cta_text',
        'image','custom_availability','booking_flow','forms_assignment',
        'reorder_settings','meta','active',
    ];

    protected $casts = [
        'custom_availability' => 'bool',
        'booking_flow' => 'array',
        'forms_assignment' => 'array',
        'reorder_settings' => 'array',
        'meta' => 'array',
        'active' => 'bool',
    ];
    public function products()
    {
        return $this->belongsToMany(\App\Models\Product::class, 'product_service')
            ->withPivot(['active', 'sort_order', 'min_qty', 'max_qty', 'price'])
            ->withTimestamps()
            ->orderBy('product_service.sort_order');
    }
    public function rafForm() { return $this->belongsTo(\App\Models\ClinicForm::class, 'raf_form_id'); }
    public function consultationAdviceForm() { return $this->belongsTo(\App\Models\ClinicForm::class, 'consultation_advice_form_id'); }
    public function pharmacistDeclarationForm() { return $this->belongsTo(\App\Models\ClinicForm::class, 'pharmacist_declaration_form_id'); }
    public function clinicalNotesForm() { return $this->belongsTo(\App\Models\ClinicForm::class, 'clinical_notes_form_id'); }
    public function reorderForm() { return $this->belongsTo(\App\Models\ClinicForm::class, 'reorder_form_id'); }
}
