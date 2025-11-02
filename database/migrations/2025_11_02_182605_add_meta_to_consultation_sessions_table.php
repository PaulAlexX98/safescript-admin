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
        if (! Schema::hasColumn('consultation_sessions', 'meta')) {
            Schema::table('consultation_sessions', function (Blueprint $table) {
                $table->json('meta')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consultation_sessions', 'meta')) {
            Schema::table('consultation_sessions', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
