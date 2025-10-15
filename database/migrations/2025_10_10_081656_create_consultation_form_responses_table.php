<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('consultation_form_responses')) {
            Schema::create('consultation_form_responses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('consultation_session_id');
                $table->unsignedBigInteger('clinic_form_id')->nullable();
                $table->string('form_type', 40);
                $table->string('step_slug', 40)->nullable();
                $table->string('service_slug', 100)->nullable();
                $table->string('treatment_slug', 100)->nullable();
                $table->unsignedInteger('form_version')->default(1);
                $table->json('data')->nullable();
                $table->boolean('is_complete')->default(false);
                $table->timestamp('completed_at')->nullable();
                $table->string('patient_context', 20)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_form_responses');
    }
};
