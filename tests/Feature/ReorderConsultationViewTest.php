<?php

namespace Tests\Feature;

use App\Models\ClinicForm;
use App\Models\ConsultationSession;
use App\Models\Order;
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

    public function test_parent_information_answer_is_not_used_as_information_details(): void
    {
        $session = new ConsultationSession([
            'service_slug' => 'weight-management',
        ]);
        $session->id = 124;

        $form = new ClinicForm([
            'schema' => [
                [
                    'type' => 'select',
                    'data' => [
                        'key' => 'information',
                        'label' => 'Is there anything else we should know?',
                        'options' => [
                            ['value' => 'yes', 'label' => 'Yes'],
                            ['value' => 'no', 'label' => 'No'],
                        ],
                    ],
                ],
                [
                    'type' => 'text_input',
                    'data' => [
                        'key' => 'information-details',
                        'label' => 'Please give us details',
                        'showIf' => [
                            'enabled' => true,
                            'field' => 'information',
                            'equals' => null,
                            'in' => ['yes'],
                        ],
                    ],
                ],
            ],
        ]);
        $form->id = 457;

        DB::table('consultation_form_responses')->insert([
            'consultation_session_id' => $session->id,
            'clinic_form_id' => $form->id,
            'data' => json_encode(['information' => 'yes'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = view('consultations.reorder', compact('session', 'form'))->render();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="information-details"[^>]*value=""/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*name="information-details"[^>]*value="yes"/',
            $html,
        );
    }

    public function test_required_banner_starts_hidden_to_avoid_a_false_warning(): void
    {
        $session = new ConsultationSession([
            'service_slug' => 'weight-management',
        ]);
        $session->id = 125;

        $form = new ClinicForm([
            'schema' => [
                [
                    'type' => 'radio',
                    'data' => [
                        'key' => 'tolerated-dose',
                        'label' => 'Have you tolerated your current dose well',
                        'required' => true,
                        'options' => [
                            ['value' => 'yes', 'label' => 'Yes'],
                            ['value' => 'no', 'label' => 'No'],
                        ],
                    ],
                ],
            ],
        ]);
        $form->id = 458;

        DB::table('consultation_form_responses')->insert([
            'consultation_session_id' => $session->id,
            'clinic_form_id' => $form->id,
            'data' => json_encode(['tolerated-dose' => 'yes'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = view('consultations.reorder', compact('session', 'form'))->render();

        $this->assertStringContainsString(
            'id="cf_required_banner" class="cf-required-banner is-hidden"',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/name="tolerated-dose"[^>]*value="yes"[^>]*checked/',
            $html,
        );
    }

    public function test_completion_page_prefers_current_answers_over_old_order_metadata(): void
    {
        $session = new ConsultationSession([
            'meta' => ['information' => 'no'],
        ]);
        $session->id = 126;

        $order = new Order([
            'meta' => ['information' => 'no'],
        ]);

        $html = view('consultations.complete', [
            'session' => $session,
            'order' => $order,
            'patient' => null,
            'oldData' => [
                'information' => 'yes',
                'information-details' => 'Please deliver on Friday',
            ],
        ])->render();

        $this->assertStringNotContainsString('<div class="cf-label">Information</div>', $html);
        $this->assertStringNotContainsString('Information Details', $html);
        $this->assertStringContainsString('Please deliver on Friday', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<div class="cf-label">Information<\/div>\s*<div class="text-sm">no<\/div>/',
            $html,
        );
    }
}
