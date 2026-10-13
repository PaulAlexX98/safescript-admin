<?php

namespace Tests\Feature;

use App\Models\ClinicForm;
use App\Models\ConsultationSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReorderConsultationViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('consultation_form_responses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consultation_session_id');
            $table->unsignedBigInteger('clinic_form_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('consultation_form_responses');

        parent::tearDown();
    }

    public function test_multi_select_answer_is_not_rendered_as_a_b12_textarea_value(): void
    {
        $session = new ConsultationSession([
            'service_slug' => 'vitamin-b12',
        ]);
        $session->id = 123;

        $form = new ClinicForm([
            'schema' => [
                [
                    'type' => 'section',
                    'data' => ['label' => 'Symptoms'],
                ],
                [
                    'type' => 'select',
                    'data' => [
                        'key' => 'current_b12_symptoms',
                        'label' => 'Current B12 symptoms',
                        'multiple' => true,
                        'options' => ['Fatigue', 'Pins and needles'],
                    ],
                ],
                [
                    'type' => 'textarea',
                    'data' => [
                        'key' => 'other_b12_symptom',
                        'label' => 'Other B12 symptom',
                    ],
                ],
            ],
        ]);
        $form->id = 456;

        DB::table('consultation_form_responses')->insert([
            'consultation_session_id' => $session->id,
            'clinic_form_id' => $form->id,
            'data' => json_encode([
                'current_b12_symptoms' => ['Fatigue'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = view('consultations.reorder', compact('session', 'form'))->render();

        $this->assertStringContainsString('name="other-b12-symptom"', $html);
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="other-b12-symptom"[^>]*><\/textarea>/',
            $html,
        );
    }
}
