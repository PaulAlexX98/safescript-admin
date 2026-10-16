<?php

namespace Tests\Feature;

use App\Models\ClinicForm;
use App\Models\Order;
use App\Models\Service;
use App\Services\Consultations\StartConsultation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StartConsultationServiceResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('dob')->nullable();
            $table->timestamps();
        });

        Schema::create('clinic_forms', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('schema')->nullable();
            $table->string('form_type')->nullable();
            $table->string('service_slug')->nullable();
            $table->string('treatment_slug')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('raf_form_id')->nullable();
            $table->unsignedBigInteger('advice_form_id')->nullable();
            $table->unsignedBigInteger('pharmacist_declaration_form_id')->nullable();
            $table->unsignedBigInteger('clinical_notes_form_id')->nullable();
            $table->unsignedBigInteger('reorder_form_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->default('pending');
            $table->string('booking_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('service')->nullable();
            $table->string('service_slug')->nullable();
            $table->string('treatment')->nullable();
            $table->string('treatment_slug')->nullable();
            $table->json('templates')->nullable();
            $table->json('steps')->nullable();
            $table->unsignedInteger('current')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_form_responses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('consultation_session_id');
            $table->string('form_type')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        DB::table('users')->insert([
            'id' => 1,
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'email' => 'patient@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('consultation_form_responses');
        Schema::dropIfExists('consultation_sessions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('services');
        Schema::dropIfExists('clinic_forms');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_walk_in_uses_flat_service_id_and_slug_before_display_name(): void
    {
        $covidRaf = $this->createForm('Private Covid Vaccine - RAF', 'raf', 'private-covid-vaccination');
        $periodDelayRaf = $this->createForm('Period Delay - RAF', 'raf');

        $service = Service::query()->create([
            'name' => 'Private Covid Vaccine',
            'slug' => 'private-covid-vaccination',
            'raf_form_id' => $covidRaf->id,
        ]);

        $order = $this->createOrder('PWMN131299', [
            'source' => 'walk_in',
            'service_id' => $service->id,
            'service_slug' => 'private-covid-vaccination',
            'service' => 'Private Covid Vaccine',
            'selectedProduct' => ['name' => 'Comirnaty XFG 30mcg PFS X1'],
        ]);

        $session = app(StartConsultation::class)($order, ['desired_type' => 'risk_assessment']);

        $this->assertSame('private-covid-vaccination', $session->service);
        $this->assertSame($covidRaf->id, $session->templates['raf']['id']);
        $this->assertSame('Private Covid Vaccine - RAF', $session->templates['raf']['name']);
        $this->assertNotSame($periodDelayRaf->id, $session->templates['raf']['id']);
    }

    public function test_legacy_normal_order_display_name_resolution_still_works(): void
    {
        $weightRaf = $this->createForm('Weight Management - RAF', 'raf', 'weight-management');

        Service::query()->create([
            'name' => 'Weight Management',
            'slug' => 'weight-management',
            'raf_form_id' => $weightRaf->id,
        ]);

        $order = $this->createOrder('NORMAL100001', [
            'service' => 'Weight Management',
            'selectedProduct' => ['name' => 'Mounjaro 5mg'],
        ]);

        $session = app(StartConsultation::class)($order, ['desired_type' => 'risk_assessment']);

        $this->assertSame('weight-management', $session->service);
        $this->assertSame($weightRaf->id, $session->templates['raf']['id']);
    }

    private function createForm(string $name, string $type, ?string $serviceSlug = null): ClinicForm
    {
        return ClinicForm::query()->create([
            'name' => $name,
            'schema' => [],
            'form_type' => $type,
            'service_slug' => $serviceSlug,
            'version' => 1,
            'is_active' => true,
        ]);
    }

    private function createOrder(string $reference, array $meta): Order
    {
        return Order::query()->create([
            'reference' => $reference,
            'status' => 'pending',
            'booking_status' => 'pending',
            'payment_status' => 'Paid at pharmacy',
            'user_id' => 1,
            'patient_id' => 1,
            'meta' => $meta,
        ]);
    }
}
