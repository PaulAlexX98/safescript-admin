<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'name')) {
                $table->string('name')->after('id');
            }

            if (! Schema::hasColumn('services', 'slug')) {
                $table->string('slug')->unique()->after('name');
            }

            if (! Schema::hasColumn('services', 'description')) {
                $table->text('description')->nullable()->after('slug');
            }

            if (! Schema::hasColumn('services', 'booking_flow')) {
                $table->json('booking_flow')->nullable()->after('description');
            }

            if (! Schema::hasColumn('services', 'forms_assignment')) {
                $table->json('forms_assignment')->nullable()->after('booking_flow');
            }

            if (! Schema::hasColumn('services', 'status')) {
                $table->string('status')->default('draft')->after('forms_assignment'); // draft|published
            }

            if (! Schema::hasColumn('services', 'active')) {
                $table->boolean('active')->default(true)->after('status');
            }

            if (! Schema::hasColumn('services', 'view_type')) {
                $table->string('view_type')->default('same_tab')->after('active'); // same_tab|new_tab
            }

            if (! Schema::hasColumn('services', 'cta_text')) {
                $table->string('cta_text')->nullable()->after('view_type');
            }

            if (! Schema::hasColumn('services', 'image')) {
                $table->string('image')->nullable()->after('cta_text');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('services')) {
            return;
        }

        Schema::table('services', function (Blueprint $table) {
            $columns = [
                'name',
                'slug',
                'description',
                'booking_flow',
                'forms_assignment',
                'status',
                'active',
                'view_type',
                'cta_text',
                'image',
            ];

            $drop = [];

            foreach ($columns as $column) {
                if (Schema::hasColumn('services', $column)) {
                    $drop[] = $column;
                }
            }

            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};