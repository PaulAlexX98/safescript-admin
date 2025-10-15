<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ClinicForm;

class ClinicFormSeeder extends Seeder
{
    public function run(): void
    {
        $forms = [
            [
                'name'          => 'Weight Management (Mounjaro) – Advice',
                'description'   => 'Template for Mounjaro pharmacist advice step.',
                'form_type'     => 'advice',
                'service_slug'  => 'weight-management-service',
                'treatment_slug'=> 'mounjaro',
                'schema'        => [],
            ],
            [
                'name'          => 'Weight Management (Mounjaro) – Declaration',
                'description'   => 'Pharmacist declaration template for Mounjaro consultations.',
                'form_type'     => 'declaration',
                'service_slug'  => 'weight-management-service',
                'treatment_slug'=> 'mounjaro',
                'schema'        => [],
            ],
            [
                'name'          => 'Weight Loss Injections – Record of Supply',
                'description'   => 'Record of supply for Mounjaro and other weight loss treatments.',
                'form_type'     => 'supply',
                'service_slug'  => 'weight-management-service',
                'treatment_slug'=> 'mounjaro',
                'schema'        => [],
            ],
        ];

        foreach ($forms as $form) {
            ClinicForm::updateOrCreate(
                [
                    'form_type'      => $form['form_type'],
                    'service_slug'   => $form['service_slug'],
                    'treatment_slug' => $form['treatment_slug'],
                ],
                [
                    'name'        => $form['name'],
                    'description' => $form['description'],
                    'schema'      => $form['schema'],
                    'version'     => 1,
                    'is_active'   => true,
                ]
            );
        }
    }
}