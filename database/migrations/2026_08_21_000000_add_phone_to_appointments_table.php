<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('appointments') || Schema::hasColumn('appointments', 'phone')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('appointments') && Schema::hasColumn('appointments', 'phone')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropColumn('phone');
            });
        }
    }
};
