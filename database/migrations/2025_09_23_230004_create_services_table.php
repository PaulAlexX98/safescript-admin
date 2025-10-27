<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['draft','published'])->default('draft');
            $table->string('view_type')->default('same_tab'); // same_tab | new_tab
            $table->string('cta_text')->nullable()->default('Start Consultation');
            $table->string('image')->nullable(); // path
            $table->boolean('custom_availability')->default(false);
            $table->json('booking_flow')->nullable();  // steps array
            $table->json('forms_assignment')->nullable(); // map of raf, advice, patient forms
            $table->json('reorder_settings')->nullable(); // dosage up/down etc
            $table->json('meta')->nullable();            // room for extras
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('services'); }
};
