<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('consultation_sessions', 'templates')) {
                $table->json('templates')->nullable();
            }
            if (!Schema::hasColumn('consultation_sessions', 'steps')) {
                $table->json('steps')->nullable();
            }
            if (!Schema::hasColumn('consultation_sessions', 'meta')) {
                $table->json('meta')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('consultation_sessions', 'templates')) {
                $table->dropColumn('templates');
            }
            if (Schema::hasColumn('consultation_sessions', 'steps')) {
                $table->dropColumn('steps');
            }
            if (Schema::hasColumn('consultation_sessions', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }
};
