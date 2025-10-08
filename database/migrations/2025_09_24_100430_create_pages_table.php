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
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');                 // “NAME” in the screenshot
            $table->string('slug')->unique();
            $table->string('template')->default('Default');
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->longText('content')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();                    // gives Created/Updated
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
