<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gp_contacts')) {
            return;
        }

        Schema::create('gp_contacts', function (Blueprint $table) {
            $table->id();
            $table->text('gp_address');
            $table->text('normalized_address');
            $table->char('address_hash', 64)->unique();
            $table->string('email', 254);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gp_contacts');
    }
};
