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
            || Schema::hasIndex('orders', 'orders_meta_fulltext')
        ) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->fullText('meta', 'orders_meta_fulltext');
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('orders')
            && Schema::hasIndex('orders', 'orders_meta_fulltext')
        ) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropFullText('orders_meta_fulltext');
            });
        }
    }
};
