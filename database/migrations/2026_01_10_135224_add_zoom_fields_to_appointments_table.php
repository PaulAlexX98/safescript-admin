<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable();
            }

            if (! Schema::hasColumn('appointments', 'zoom_join_url')) {
                $table->text('zoom_join_url')->nullable();
            }

            if (! Schema::hasColumn('appointments', 'zoom_start_url')) {
                $table->text('zoom_start_url')->nullable();
            }

            if (! Schema::hasColumn('appointments', 'zoom_created_at')) {
                $table->timestamp('zoom_created_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $cols = [];

            foreach (['zoom_meeting_id', 'zoom_join_url', 'zoom_start_url', 'zoom_created_at'] as $col) {
                if (Schema::hasColumn('appointments', $col)) {
                    $cols[] = $col;
                }
            }

            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};