<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('clinic_forms', 'form_type')) {
                $table->string('form_type', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('clinic_forms', 'form_type')) {
                $table->dropColumn('form_type');
            }
        });
    }
};