<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clinic_forms')) {
            return;
        }

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('clinic_forms', 'name')) {
                $table->string('name')->nullable();
            }
            if (! Schema::hasColumn('clinic_forms', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::hasColumn('clinic_forms', 'schema')) {
                $table->json('schema')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clinic_forms')) {
            return;
        }

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('clinic_forms', 'schema')) {
                $table->dropColumn('schema');
            }
            if (Schema::hasColumn('clinic_forms', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('clinic_forms', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};