<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If the table doesn't exist, create it fresh.
        if (! Schema::hasTable('appointments')) {
            Schema::create('appointments', function (Blueprint $table) {
                $table->id();

                // Core scheduling
                $table->dateTime('start_at');
                $table->dateTime('end_at')->nullable();

                // Patient & service (adapt as needed)
                $table->string('patient_name')->nullable();
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('service_slug')->nullable();
                $table->string('service_name')->nullable();

                // Status & links
                $table->string('status')->default('booked');
                $table->foreignId('order_id')->nullable()->index();

                $table->softDeletes();
                $table->timestamps();
            });

            return;
        }

        // Otherwise, the table already exists — add any missing columns defensively.
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'start_at')) {
                $table->dateTime('start_at')->nullable()->after('id');
            }
            if (! Schema::hasColumn('appointments', 'end_at')) {
                $table->dateTime('end_at')->nullable()->after('start_at');
            }
            if (! Schema::hasColumn('appointments', 'patient_name')) {
                $table->string('patient_name')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'first_name')) {
                $table->string('first_name')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'last_name')) {
                $table->string('last_name')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'service_slug')) {
                $table->string('service_slug')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'service_name')) {
                $table->string('service_name')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'status')) {
                $table->string('status')->default('booked');
            }
            if (! Schema::hasColumn('appointments', 'order_id')) {
                $table->foreignId('order_id')->nullable()->index();
            }
            if (! Schema::hasColumn('appointments', 'deleted_at')) {
                $table->softDeletes();
            }
            // Add timestamps individually to avoid duplicate column errors.
            if (! Schema::hasColumn('appointments', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('appointments', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: do not drop an existing production table.
        // If you created this table via this migration and need to roll back locally,
        // you may uncomment the line below at your own risk.
        // Schema::dropIfExists('appointments');
    }
};