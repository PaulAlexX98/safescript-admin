<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();

            // FK to products
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->string('title');
            $table->decimal('price', 8, 2)->nullable();

            // ordering & inventory
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedSmallInteger('max_qty')->default(0); // <-- required by your code

            $table->string('status')->default('draft'); // 'draft' | 'published'
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};