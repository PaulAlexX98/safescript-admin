<x-filament-panels::page>
@php
    $form = \App\Models\ClinicForm::where('form_type','risk')
        ->where('service_slug',$serviceSlugForForm)
        ->where('treatment_slug',$treatmentSlugForForm)
        ->where('is_active',1)
        ->orderByDesc('version')
        ->first();
    $schema = is_array($form?->schema) ? $form->schema : (json_decode($form->schema ?? '[]', true) ?: []);
    $resp = \App\Models\ConsultationFormResponse::where([
        'consultation_session_id'=>$session->id,
        'form_type'=>'risk',
        'service_slug'=>$serviceSlugForForm,
        'treatment_slug'=>$treatmentSlugForForm,
    ])->first();
    $oldData = $resp?->data ?? [];
@endphp
<x-filament::card class="rounded-xl">
    @include('consultations.forms._form', ['session'=>$session,'slug'=>'risk-assessment','form'=>$form,'schema'=>$schema,'oldData'=>$oldData])
</x-filament::card>
</x-filament-panels::page>