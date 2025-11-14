<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            $table->json('raf_schema')->nullable();
            $table->unsignedInteger('raf_version')->default(1);
            $table->string('raf_status', 20)->default('published');
        });
    }

    public function down(): void
    {
        Schema::table('clinic_forms', function (Blueprint $table) {
            $table->dropColumn(['raf_schema', 'raf_version', 'raf_status']);
        });
    }
};