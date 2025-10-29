<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                // “Main clinic”
            $table->string('service_slug')->nullable()->index();   // optional: tie to a service
            $table->string('timezone')->default('Europe/London');

            $table->unsignedSmallInteger('slot_minutes')->default(15);
            $table->unsignedSmallInteger('capacity')->default(1);

            // Week template: mon..sun => { open: bool, start: "09:00", end: "17:00" }
            $table->json('week');

            // Date-specific overrides (holidays, short days, remove a time)
            // [{ "date":"2025-12-25","open":false,"reason":"Christmas" },
            //  { "date":"2025-11-03","open":true,"start":"10:00","end":"16:00","blackouts":["12:00"] }]
            $table->json('overrides')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
