<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'slug')) {
                $table->string('slug')->unique()->after('id');
            }
            if (! Schema::hasColumn('services', 'name')) {
                $table->string('name')->nullable()->after('slug');
            }
            if (! Schema::hasColumn('services', 'status')) {
                $table->string('status')->default('published')->after('name');
            }
            // optional JSON for future
            if (! Schema::hasColumn('services', 'meta')) {
                $table->json('meta')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'meta'))   $table->dropColumn('meta');
            if (Schema::hasColumn('services', 'status')) $table->dropColumn('status');
            if (Schema::hasColumn('services', 'name'))   $table->dropColumn('name');
            if (Schema::hasColumn('services', 'slug'))   $table->dropUnique(['slug']); // drop index
        });
    }
};