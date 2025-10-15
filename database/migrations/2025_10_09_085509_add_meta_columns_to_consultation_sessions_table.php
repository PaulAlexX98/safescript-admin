<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('consultation_sessions', 'service')) {
                $table->string('service')->nullable()->after('order_id');
            }
            if (! Schema::hasColumn('consultation_sessions', 'treatment')) {
                $table->string('treatment')->nullable()->after('service');
            }
            if (! Schema::hasColumn('consultation_sessions', 'templates')) {
                $table->json('templates')->nullable()->after('treatment');
            }
            if (! Schema::hasColumn('consultation_sessions', 'steps')) {
                $table->json('steps')->nullable()->after('templates');
            }
            if (! Schema::hasColumn('consultation_sessions', 'current')) {
                $table->unsignedInteger('current')->default(0)->after('steps');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('consultation_sessions', 'current'))   $table->dropColumn('current');
            if (Schema::hasColumn('consultation_sessions', 'steps'))     $table->dropColumn('steps');
            if (Schema::hasColumn('consultation_sessions', 'templates')) $table->dropColumn('templates');
            if (Schema::hasColumn('consultation_sessions', 'treatment')) $table->dropColumn('treatment');
            if (Schema::hasColumn('consultation_sessions', 'service'))   $table->dropColumn('service');
        });
    }
};