<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consultation_sessions')) {
            return;
        }

        Schema::create('consultation_sessions', function (Blueprint $table) {
            $table->id();

            // Relation to the ApprovedOrder
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Identify which service and treatment this consultation belongs to
            $table->string('service_slug')->nullable();
            $table->string('treatment_slug')->nullable();

            // JSON snapshot of all assigned forms (RAF, advice, declaration, supply)
            $table->json('snapshot')->nullable();

            // Current consultation state (e.g., "in_progress", "completed")
            $table->string('status')->default('in_progress');

            // Optional user tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_sessions');
    }
};