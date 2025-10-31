<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Service extends Model
{
    protected $fillable = [
        'name','slug','description','status','view_type','cta_text',
        'image','custom_availability','booking_flow','forms_assignment',
        'reorder_settings','meta','active',
        'raf_form_id','advice_form_id','pharmacist_declaration_form_id','clinical_notes_form_id','reorder_form_id',
    ];

    protected $casts = [
        'custom_availability' => 'bool',
        'booking_flow' => 'array',
        'forms_assignment' => 'array',
        'reorder_settings' => 'array',
        'meta' => 'array',
        'active' => 'bool',
        'raf_form_id' => 'integer',
        'advice_form_id' => 'integer',
        'pharmacist_declaration_form_id' => 'integer',
        'clinical_notes_form_id' => 'integer',
        'reorder_form_id' => 'integer',
    ];
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_service')
            ->withPivot(['active', 'sort_order', 'min_qty', 'max_qty', 'price'])
            ->withTimestamps()
            ->orderBy('product_service.sort_order');
    }
    public function rafForm(): BelongsTo { return $this->belongsTo(ClinicForm::class, 'raf_form_id'); }
    public function adviceForm(): BelongsTo { return $this->belongsTo(ClinicForm::class, 'advice_form_id'); }
    public function pharmacistDeclarationForm(): BelongsTo { return $this->belongsTo(ClinicForm::class, 'pharmacist_declaration_form_id'); }
    public function clinicalNotesForm(): BelongsTo { return $this->belongsTo(ClinicForm::class, 'clinical_notes_form_id'); }
    public function reorderForm(): BelongsTo { return $this->belongsTo(ClinicForm::class, 'reorder_form_id'); }
}
