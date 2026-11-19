<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('appointments') && ! Schema::hasColumn('appointments', 'notes')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'notes')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
