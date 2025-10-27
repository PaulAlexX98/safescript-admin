<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('clinic_forms', 'type')) {
                $table->dropColumn('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('clinic_forms', 'type')) {
                $table->string('type', 32)->nullable()->index();
            }
        });
    }
};