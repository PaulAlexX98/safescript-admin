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
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'meta_title')) {
                $table->string('meta_title', 60)->nullable()->after('description');
            }
            if (! Schema::hasColumn('pages', 'meta_description')) {
                $table->string('meta_description', 160)->nullable()->after('meta_title');
            }
            if (! Schema::hasColumn('pages', 'meta')) {
                $table->json('meta')->nullable()->after('meta_description');
            }
            // if gallery should persist as array
            if (! Schema::hasColumn('pages', 'gallery')) {
                $table->json('gallery')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description', 'meta', 'gallery']);
        });
    }
};
