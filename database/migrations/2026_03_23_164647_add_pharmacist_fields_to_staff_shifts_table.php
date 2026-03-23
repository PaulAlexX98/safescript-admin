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
        Schema::table('staff_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_shifts', 'pharmacist_name')) {
                $table->string('pharmacist_name')->nullable()->after('shift_date');
            }

            if (! Schema::hasColumn('staff_shifts', 'gphc_number')) {
                $table->string('gphc_number')->nullable()->after('pharmacist_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('staff_shifts', 'gphc_number')) {
                $table->dropColumn('gphc_number');
            }

            if (Schema::hasColumn('staff_shifts', 'pharmacist_name')) {
                $table->dropColumn('pharmacist_name');
            }
        });
    }
};
