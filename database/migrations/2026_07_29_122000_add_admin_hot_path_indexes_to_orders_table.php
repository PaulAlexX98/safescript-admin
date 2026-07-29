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
            if (Schema::hasColumn('orders', 'paid_at')
                && ! Schema::hasIndex('orders', 'orders_paid_at_index')) {
                $table->index('paid_at');
            }

            if (Schema::hasColumn('orders', 'status')
                && Schema::hasColumn('orders', 'created_at')
                && Schema::hasColumn('orders', 'id')
                && ! Schema::hasIndex('orders', 'orders_status_created_at_id_index')) {
                $table->index(['status', 'created_at', 'id']);
            }

            if (Schema::hasColumn('orders', 'payment_status')
                && Schema::hasColumn('orders', 'status')
                && Schema::hasColumn('orders', 'approved_at')
                && Schema::hasColumn('orders', 'created_at')
                && ! Schema::hasIndex('orders', 'orders_payment_status_status_approved_created_index')) {
                $table->index(
                    ['payment_status', 'status', 'approved_at', 'created_at'],
                    'orders_payment_status_status_approved_created_index'
                );
            }

            if (Schema::hasColumn('orders', 'user_id')
                && Schema::hasColumn('orders', 'created_at')
                && Schema::hasColumn('orders', 'payment_status')
                && Schema::hasColumn('orders', 'status')
                && ! Schema::hasIndex('orders', 'orders_user_created_payment_status_index')) {
                $table->index(
                    ['user_id', 'created_at', 'payment_status', 'status'],
                    'orders_user_created_payment_status_index'
                );
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
                'orders_paid_at_index',
                'orders_status_created_at_id_index',
                'orders_payment_status_status_approved_created_index',
                'orders_user_created_payment_status_index',
            ] as $index) {
                if (Schema::hasIndex('orders', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
