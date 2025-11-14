<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_service', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('product_id');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('min_qty')->default(1);
            $table->unsignedTinyInteger('max_qty')->default(1);
            $table->decimal('price', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'product_id']);
        });

        if (Schema::hasTable('services')) {
            Schema::table('product_service', function (Blueprint $table) {
                $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('product_service', function (Blueprint $table) {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_service')) {
            Schema::table('product_service', function (Blueprint $table) {
                $table->dropForeign(['service_id']);
                $table->dropForeign(['product_id']);
            });
        }

        Schema::dropIfExists('product_service');
    }
};