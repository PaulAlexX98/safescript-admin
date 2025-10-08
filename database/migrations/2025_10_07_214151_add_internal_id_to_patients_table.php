<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (!Schema::hasColumn('patients', 'internal_id')) {
                $table->string('internal_id', 32)->nullable()->unique()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (Schema::hasColumn('patients', 'internal_id')) {
                $table->dropUnique(['internal_id']);
                $table->dropColumn('internal_id');
            }
        });
    }
};