<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            $table->json('raf_schema')->nullable()->after('schema_json');
            $table->unsignedInteger('raf_version')->default(1)->after('raf_schema');
            $table->string('raf_status', 20)->default('published')->after('raf_version');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            $table->dropColumn(['raf_schema', 'raf_version', 'raf_status']);
        });
    }
};