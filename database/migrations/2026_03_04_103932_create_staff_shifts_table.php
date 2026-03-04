<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_shifts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('shift_date');

            $table->timestamp('clocked_in_at')->nullable();
            $table->timestamp('clocked_out_at')->nullable();

            $table->string('clock_in_ip', 45)->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->text('clock_in_ua')->nullable();
            $table->text('clock_out_ua')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'shift_date']);
            $table->index(['user_id', 'clocked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_shifts');
    }
};