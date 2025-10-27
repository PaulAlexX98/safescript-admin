<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinic_forms')) return;

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('clinic_forms', 'form_type')) {
                $table->string('form_type', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clinic_forms')) return;

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('clinic_forms', 'form_type')) {
                // If an index exists it will be removed automatically when the column is dropped.
                $table->dropColumn('form_type');
            }
        });
    }
};