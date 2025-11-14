<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            // Table already exists in this database, so do not try to recreate it.
            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id'); // BIGINT UNSIGNED – matches services.id
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('type')->nullable();            // e.g. 'medication'
            $table->string('image_url')->nullable();
            $table->decimal('price_from', 10, 2)->nullable();
            $table->string('status')->default('published'); // 'draft'|'published'
            $table->unsignedTinyInteger('max_qty')->default(1);
            $table->boolean('is_virtual')->default(false);
            $table->timestamps();

            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};