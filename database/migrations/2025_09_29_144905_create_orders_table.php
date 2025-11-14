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
        if (Schema::hasTable('orders')) {
            return;
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Link to users (nullable for guest flows)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Business identifiers & state
            $table->string('reference')->nullable()->index();      // external ref / human-friendly
            $table->string('status')->default('pending')->index(); // pending, approved, completed, rejected, unpaid
            $table->string('payment_status')->nullable()->index(); // paid, unpaid, refunded, etc.
            $table->timestamp('paid_at')->nullable();

            // Legacy / transitional status used by older code (safe to keep for now)
            $table->string('booking_status')->nullable()->index();

            // Arbitrary order payload (items, service, answers, etc.)
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
