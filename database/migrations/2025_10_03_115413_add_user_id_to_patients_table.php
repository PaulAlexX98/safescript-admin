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
        if (! Schema::hasTable('patients')) {
            return;
        }

        Schema::table('patients', function (Blueprint $table) {
            if (! Schema::hasColumn('patients', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete()
                    ->after('id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('patients')) {
            return;
        }

        Schema::table('patients', function (Blueprint $table) {
            if (Schema::hasColumn('patients', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
