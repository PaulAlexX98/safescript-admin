<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'max_bookable_quantity')) {
                $table->unsignedInteger('max_bookable_quantity')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'max_bookable_quantity')) {
                $table->dropColumn('max_bookable_quantity');
            }
        });
    }
};