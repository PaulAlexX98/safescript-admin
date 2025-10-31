<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1 add new column if missing
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'advice_form_id')) {
                $table->unsignedBigInteger('advice_form_id')->nullable()->after('raf_form_id');
            }
        });

        // 2 copy data from legacy column if it exists
        if (Schema::hasColumn('services', 'consultation_advice_form_id')) {
            DB::statement('UPDATE services SET advice_form_id = consultation_advice_form_id WHERE advice_form_id IS NULL');
        }

        // 3 drop old FK then old column if present
        if (Schema::hasColumn('services', 'consultation_advice_form_id')) {
            Schema::table('services', function (Blueprint $table) {
                try { $table->dropForeign(['consultation_advice_form_id']); } catch (\Throwable $e) {}
            });
            Schema::table('services', function (Blueprint $table) {
                try { $table->dropColumn('consultation_advice_form_id'); } catch (\Throwable $e) {}
            });
        }

        // 4 add FK on the new column
        Schema::table('services', function (Blueprint $table) {
            try {
                $table->foreign('advice_form_id')
                    ->references('id')->on('clinic_forms')
                    ->nullOnDelete();
            } catch (\Throwable $e) {
                // constraint may already exist
            }
        });
    }

    public function down(): void
    {
        // recreate legacy column
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'consultation_advice_form_id')) {
                $table->unsignedBigInteger('consultation_advice_form_id')->nullable()->after('raf_form_id');
            }
        });

        // move values back
        if (Schema::hasColumn('services', 'advice_form_id')) {
            DB::statement('UPDATE services SET consultation_advice_form_id = advice_form_id WHERE consultation_advice_form_id IS NULL');
        }

        // drop FK and advice_form_id
        Schema::table('services', function (Blueprint $table) {
            try { $table->dropForeign(['advice_form_id']); } catch (\Throwable $e) {}
        });
        Schema::table('services', function (Blueprint $table) {
            try { $table->dropColumn('advice_form_id'); } catch (\Throwable $e) {}
        });

        // re add FK on legacy
        Schema::table('services', function (Blueprint $table) {
            try {
                $table->foreign('consultation_advice_form_id')
                    ->references('id')->on('clinic_forms')
                    ->nullOnDelete();
            } catch (\Throwable $e) {}
        });
    }
};