<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendCompletedOrderGpNotificationsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('gp_surgery')->nullable();
            $table->string('gp_email')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->json('meta')->nullable();
            $table->json('templates')->nullable();
            $table->json('steps')->nullable();
            $table->unsignedInteger('current')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('consultation_sessions');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_dry_run_reports_eligible_orders_without_sending(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'gp_email' => 'gp@example.nhs.uk'],
            ['id' => 2, 'gp_email' => null],
        ]);

        DB::table('orders')->insert([
            [
                'id' => 10,
                'user_id' => 1,
                'reference' => 'ELIGIBLE',
                'status' => 'completed',
                'completed_at' => '2026-11-10 12:00:00',
                'meta' => json_encode(['consultation_session_id' => 100]),
            ],
            [
                'id' => 11,
                'user_id' => 1,
                'reference' => 'ALREADY-SENT',
                'status' => 'completed',
                'completed_at' => '2026-11-11 12:00:00',
                'meta' => json_encode([
                    'consultation_session_id' => 101,
                    'gp_treatment_notification' => ['sent_at' => '2026-11-11T12:05:00Z'],
                ]),
            ],
            [
                'id' => 12,
                'user_id' => 2,
                'reference' => 'NO-GP-EMAIL',
                'status' => 'completed',
                'completed_at' => '2026-11-12 12:00:00',
                'meta' => json_encode(['consultation_session_id' => 102]),
            ],
            [
                'id' => 13,
                'user_id' => 1,
                'reference' => 'OUTSIDE-RANGE',
                'status' => 'completed',
                'completed_at' => '2026-10-31 12:00:00',
                'meta' => json_encode(['consultation_session_id' => 103]),
            ],
        ]);

        DB::table('consultation_sessions')->insert([
            ['id' => 100, 'order_id' => 10],
            ['id' => 101, 'order_id' => 11],
            ['id' => 102, 'order_id' => 12],
            ['id' => 103, 'order_id' => 13],
        ]);

        $this->artisan('gp-notifications:send-completed', [
            '--from' => '2026-11-01',
            '--to' => '2026-11-30',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('3 completed orders')
            ->expectsTable(
                ['Result', 'Orders'],
                [
                    ['Eligible', 1],
                    ['Sent', 0],
                    ['Already sent', 1],
                    ['Missing GP email', 1],
                    ['Missing consultation', 0],
                    ['Failed', 0],
                ],
            )
            ->expectsOutputToContain('Dry run only: no emails were sent.')
            ->assertSuccessful();
    }

    public function test_dates_are_required(): void
    {
        $this->artisan('gp-notifications:send-completed')
            ->expectsOutputToContain('The --from option is required')
            ->expectsOutputToContain('The --to option is required')
            ->assertFailed();
    }
}
