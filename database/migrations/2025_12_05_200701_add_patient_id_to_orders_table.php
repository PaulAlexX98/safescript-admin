<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add nullable patient_id with index
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'patient_id')) {
                $table->unsignedBigInteger('patient_id')->nullable()->after('user_id')->index();
            }
        });

        // 2) Backfill patient_id from patients.user_id
        try {
            DB::statement(<<<SQL
                UPDATE orders o
                JOIN patients p ON p.user_id = o.user_id
                SET o.patient_id = p.id
                WHERE o.patient_id IS NULL
            SQL);
        } catch (\Throwable $e) {
            // ok to skip if the driver does not support this exact syntax
        }

        // 3) Mirror into meta.patient_id where missing
        try {
            DB::statement(<<<SQL
                UPDATE orders
                SET meta = JSON_SET(meta, '$.patient_id', patient_id)
                WHERE patient_id IS NOT NULL
                  AND (JSON_EXTRACT(meta, '$.patient_id') IS NULL OR JSON_EXTRACT(meta, '$.patient_id') = 'null')
            SQL);
        } catch (\Throwable $e) {
            // ok to skip on older engines
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'patient_id')) {
                $table->dropIndex(['patient_id']);
                $table->dropColumn('patient_id');
            }
        });
    }
};