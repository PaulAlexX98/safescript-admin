<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_forms', function (Blueprint $table) {
            $table->id();

            // Basic info
            $table->string('name');
            $table->text('description')->nullable();

            // Form data
            $table->json('schema')->nullable();

            // Matching metadata
            $table->string('form_type')->nullable();       // e.g. raf | advice | declaration | supply
            $table->string('service_slug')->nullable();    // e.g. weight-management-service
            $table->string('treatment_slug')->nullable();  // e.g. mounjaro
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_forms');
    }
};