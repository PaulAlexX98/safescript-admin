<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'gp_surgery')) {
                $table->text('gp_surgery')->nullable()->after('shipping_country');
            }

            if (! Schema::hasColumn('users', 'gp_email')) {
                $table->string('gp_email', 254)->nullable()->after('gp_surgery');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('users', 'gp_email')) {
                $columns[] = 'gp_email';
            }

            if (Schema::hasColumn('users', 'gp_surgery')) {
                $columns[] = 'gp_surgery';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
