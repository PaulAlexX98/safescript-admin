<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('orders')
            || Schema::hasIndex('orders', 'orders_status_completed_at_id_index')
        ) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(
                ['status', 'completed_at', 'id'],
                'orders_status_completed_at_id_index'
            );
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('orders')
            && Schema::hasIndex('orders', 'orders_status_completed_at_id_index')
        ) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropIndex('orders_status_completed_at_id_index');
            });
        }
    }
};
