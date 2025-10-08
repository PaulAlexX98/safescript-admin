<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Only add if missing (running on fresh DB will just add them)
            if (!Schema::hasColumn('users', 'first_name'))  $table->string('first_name')->nullable()->after('id');
            if (!Schema::hasColumn('users', 'last_name'))   $table->string('last_name')->nullable()->after('first_name');
            if (!Schema::hasColumn('users', 'gender'))      $table->string('gender', 20)->nullable()->after('last_name');
            if (!Schema::hasColumn('users', 'phone'))       $table->string('phone', 50)->nullable()->after('gender');
            if (!Schema::hasColumn('users', 'dob'))         $table->date('dob')->nullable()->after('phone');

            if (!Schema::hasColumn('users', 'address1'))    $table->string('address1')->nullable()->after('dob');
            if (!Schema::hasColumn('users', 'address2'))    $table->string('address2')->nullable()->after('address1');
            if (!Schema::hasColumn('users', 'city'))        $table->string('city')->nullable()->after('address2');
            // Add county to keep alignment with your form
            if (!Schema::hasColumn('users', 'county'))      $table->string('county')->nullable()->after('city');
            if (!Schema::hasColumn('users', 'postcode'))    $table->string('postcode', 32)->nullable()->after('county');
            if (!Schema::hasColumn('users', 'country'))     $table->string('country')->nullable()->after('postcode');

            if (!Schema::hasColumn('users', 'marketing'))   $table->boolean('marketing')->default(false)->after('country');

            // Keep the existing 'name' column for compatibility with Laravel auth scaffolds
            // It already exists in most default installs; leave it alone.
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'first_name','last_name','gender','phone','dob',
                'address1','address2','city','county','postcode','country',
                'marketing'
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};