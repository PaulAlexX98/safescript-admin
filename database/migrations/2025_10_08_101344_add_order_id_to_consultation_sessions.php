<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('consultation_sessions', 'order_id')) {
                $table->foreignId('order_id')
                    ->after('id')
                    ->constrained('orders')
                    ->cascadeOnDelete()
                    ->index();
            }
            // If you want to ensure only one session per order, uncomment:
            // $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('consultation_sessions', 'order_id')) {
                // drop unique first if you added it
                // $table->dropUnique(['order_id']);
                $table->dropConstrainedForeignId('order_id'); // drops FK + column
            }
        });
    }
};