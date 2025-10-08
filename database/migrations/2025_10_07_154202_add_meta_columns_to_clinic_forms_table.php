<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('clinic_forms')) {
            return;
        }

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (! Schema::hasColumn('clinic_forms', 'form_type')) {
                $table->string('form_type')->nullable()->after('schema');
            }
            if (! Schema::hasColumn('clinic_forms', 'service_slug')) {
                $table->string('service_slug')->nullable()->after('form_type');
            }
            if (! Schema::hasColumn('clinic_forms', 'treatment_slug')) {
                $table->string('treatment_slug')->nullable()->after('service_slug');
            }
            if (! Schema::hasColumn('clinic_forms', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('treatment_slug');
            }
            if (! Schema::hasColumn('clinic_forms', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('version');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('clinic_forms')) {
            return;
        }

        Schema::table('clinic_forms', function (Blueprint $table) {
            if (Schema::hasColumn('clinic_forms', 'is_active')) {
                $table->dropColumn('is_active');
            }
            if (Schema::hasColumn('clinic_forms', 'version')) {
                $table->dropColumn('version');
            }
            if (Schema::hasColumn('clinic_forms', 'treatment_slug')) {
                $table->dropColumn('treatment_slug');
            }
            if (Schema::hasColumn('clinic_forms', 'service_slug')) {
                $table->dropColumn('service_slug');
            }
            if (Schema::hasColumn('clinic_forms', 'form_type')) {
                $table->dropColumn('form_type');
            }
        });
    }
};
