<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasIndex('orders', 'orders_status_user_id_id_index')) {
                $table->index(['status', 'user_id', 'id']);
            }
            if (! Schema::hasIndex('orders', 'orders_payment_status_paid_at_index')) {
                $table->index(['payment_status', 'paid_at']);
            }
            if (! Schema::hasIndex('orders', 'orders_status_paid_at_index')) {
                $table->index(['status', 'paid_at']);
            }
            if (Schema::hasColumn('orders', 'patient_id') && ! Schema::hasIndex('orders', 'orders_patient_id_status_id_index')) {
                $table->index(['patient_id', 'status', 'id']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            foreach ([
                'orders_status_user_id_id_index',
                'orders_payment_status_paid_at_index',
                'orders_status_paid_at_index',
                'orders_patient_id_status_id_index',
            ] as $index) {
                if (Schema::hasIndex('orders', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
