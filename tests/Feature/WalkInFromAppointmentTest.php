<?php

namespace Tests\Feature;

use App\Filament\Resources\PendingOrders\Widgets\ManualAppointmentsPending;
use App\Filament\Resources\WalkIns\Pages\CreateWalkIn;
use App\Filament\Resources\WalkIns\WalkInResource;
use App\Models\Appointment;
use App\Models\ConsultationSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Consultations\StartConsultation;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class WalkInFromAppointmentTest extends TestCase
{
    public function test_create_walk_in_warns_about_matching_patient_contact_details(): void
    {
        $this->createTables();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs((new User)->forceFill(['id' => 42, 'is_staff' => true]));

        $userId = DB::table('users')->insertGetId(['phone' => '(01234) 567-890']);
        DB::table('patients')->insert([
            'user_id' => $userId,
            'first_name' => 'Existing',
            'last_name' => 'Patient',
            'email' => 'Patient@example.test',
        ]);

        Livewire::test(CreateWalkIn::class)
            ->assertDontSee('Possible existing patient')
            ->set('data.email', 'patient@example.test')
            ->assertSee('Possible existing patient with this email address.')
            ->assertSet('data.patient_id', null)
            ->set('data.phone', '01234567890')
            ->assertSee('Possible existing patient with this phone number.')
            ->set('data.email', 'another@example.test')
            ->assertDontSee('Possible existing patient with this email address.')
            ->set('data.phone', '')
            ->assertDontSee('Possible existing patient');
    }

    #[TestWith(['create'])]
    #[TestWith(['consultation'])]
    #[TestWith(['consultation_failure'])]
    public function test_an_appointment_prefills_and_links_to_only_one_walk_in_order(string $flow): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 7, 10)->setTime(8, 0));
        $this->createTables();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs((new User)->forceFill(['id' => 42, 'is_staff' => true]));

        DB::table('services')->insert(['id' => 1, 'name' => 'Blood tests', 'slug' => 'blood-tests']);
        DB::table('service_medicines')->insert(['service_id' => 1, 'name' => 'Blood test panel']);
        $appointment = Appointment::query()->forceCreate([
            'order_reference' => 'PCAO123456',
            'start_at' => '2026-07-10 09:00:00',
            'end_at' => '2026-07-10 09:45:00',
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'email' => 'patient@example.test',
            'phone' => '01234567890',
            'service_id' => 1,
            'service_name' => 'Blood test panel',
            'notes' => 'Bring the appointment letter.',
        ]);

        $widget = Livewire::withoutLazyLoading()->test(ManualAppointmentsPending::class);
        $widget->assertTableActionVisible('startWalkInOrder', $appointment)
            ->assertTableActionHidden('viewWalkInOrder', $appointment);

        $form = Livewire::withQueryParams(['appointment' => $appointment->id])->test(CreateWalkIn::class);
        $staleForm = Livewire::withQueryParams(['appointment' => $appointment->id])->test(CreateWalkIn::class);

        $form->assertSee('Start Consultation')
            ->assertSet('data.first_name', 'Test')
            ->assertSet('data.last_name', 'Patient')
            ->assertSet('data.email', 'patient@example.test')
            ->assertSet('data.phone', '01234567890')
            ->assertSet('data.service_id', 1)
            ->assertSet('data.appointment_time_display', '10 Jul 2026, 10:00')
            ->assertSet('data.appointment_notes', 'Bring the appointment letter.');
        $this->assertSame('Blood test panel', array_values($form->get('data.items'))[0]['name']);

        if ($flow === 'create') {
            $form->call('create')->assertHasNoErrors()->assertRedirect(WalkInResource::getUrl('index'));
        } else {
            $starter = Mockery::mock(StartConsultation::class);
            $this->app->instance(StartConsultation::class, $starter);
            $starter->shouldReceive('__invoke')->once()->andReturnUsing(function (Order $order, array $context) use ($appointment, $flow): ConsultationSession {
                $this->assertSame($order->id, $appointment->fresh()->order_id);
                $this->assertSame(['desired_type' => 'risk_assessment'], $context);

                if ($flow === 'consultation_failure') {
                    throw new RuntimeException('Consultation is unavailable.');
                }

                return new ConsultationSession(['id' => 123]);
            });

            $form->set('data.first_name', '')
                ->call('createAndStartConsultation')
                ->assertHasErrors(['data.first_name' => 'required']);
            $this->assertSame(0, Order::query()->count());

            $expectedRedirect = $flow === 'consultation'
                ? route('consultations.runner.start', ['session' => 123])
                : WalkInResource::getUrl('index');

            $form->set('data.first_name', 'Test')
                ->call('createAndStartConsultation')
                ->assertHasNoErrors()
                ->assertRedirect($expectedRedirect);
        }
        $order = Order::query()->sole();
        $appointment->refresh();

        $this->assertSame($order->id, $appointment->order_id);
        $this->assertSame($order->reference, $appointment->order_reference);
        $this->assertSame('2026-07-10 09:45:00', $appointment->getRawOriginal('end_at'));
        $this->assertSame('2026-07-10T09:00:00+00:00', $order->meta['appointment_at']);
        $this->assertSame('PCAO123456', $order->meta['manual_appointment_reference']);
        $this->assertSame('Appointment notes: Bring the appointment letter.', $order->meta['admin_notes']);
        $this->assertSame('Blood test panel', $order->meta['items'][0]['name']);
        $this->assertSame(1, Appointment::query()->count());
        $this->assertTrue(ManualAppointmentsPending::query()->whereKey($appointment->id)->exists());
        $widget->call('$refresh')
            ->assertTableActionHidden('startWalkInOrder', $appointment)
            ->assertTableActionVisible('viewWalkInOrder', $appointment);

        $staleForm->call('create');
        $this->assertSame(1, Order::query()->count());
        Livewire::withQueryParams(['appointment' => $appointment->id])->test(CreateWalkIn::class)
            ->assertRedirect(WalkInResource::getUrl('view', ['record' => $order]));

        $order->delete();
        $this->assertNull($appointment->refresh()->order_id);
        $this->assertSame('PCAO123456', $appointment->order_reference);
    }

    private function createTables(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            foreach (['order_reference', 'status', 'source', 'first_name', 'last_name', 'patient_name', 'email', 'phone', 'service_name', 'service', 'service_slug', 'notes'] as $column) {
                $table->string($column)->nullable();
            }
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
        });

        Schema::create('service_medicines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->string('name');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');
            $table->string('status');
            $table->string('booking_status');
            $table->string('payment_status');
            $table->json('meta');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'first_name', 'last_name', 'gender', 'phone', 'dob', 'email', 'address1', 'address2', 'city', 'county', 'postcode', 'country', 'password'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });

        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            foreach (['first_name', 'last_name', 'dob', 'gender', 'email', 'phone', 'city', 'postcode', 'country'] as $column) {
                $table->string($column)->nullable();
            }
            $table->timestamps();
        });
    }
}
