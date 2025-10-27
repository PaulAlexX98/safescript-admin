<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // If these columns might already exist on some envs, guard with conditions in raw SQL or ignore errors.
            $table->string('name')->after('id');
            $table->string('slug')->unique()->after('name');

            $table->text('description')->nullable()->after('slug');

            // Booking flow config like { step1:'service', step2:'raf', ... }
            $table->json('booking_flow')->nullable()->after('description');

            // Form assignments like { raf: 1, advice: 2, ... }
            $table->json('forms_assignment')->nullable()->after('booking_flow');

            $table->string('status')->default('draft')->after('forms_assignment'); // draft|published
            $table->boolean('active')->default(true)->after('status');

            // Optional nice-to-haves you mentioned before:
            $table->string('view_type')->default('same_tab')->after('active'); // same_tab|new_tab
            $table->string('cta_text')->nullable()->after('view_type');
            $table->string('image')->nullable()->after('cta_text');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
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
            ]);
        });
    }
};