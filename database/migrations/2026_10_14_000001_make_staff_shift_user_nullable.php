<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('staff_shifts')->whereNull('user_id')->exists()) {
            throw new RuntimeException(
                'Cannot make staff_shifts.user_id required while historical shifts without users exist.'
            );
        }

        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('staff_shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
