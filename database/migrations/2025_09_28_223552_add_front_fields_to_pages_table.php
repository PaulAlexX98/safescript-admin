<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // if columns already exist in another env, wrap with checks as needed
            if (!Schema::hasColumn('pages', 'description'))     $table->text('description')->nullable()->after('slug');
            if (!Schema::hasColumn('pages', 'content'))         $table->longText('content')->nullable()->after('description');
            if (!Schema::hasColumn('pages', 'gallery'))         $table->json('gallery')->nullable()->after('content'); // SQLite stores as TEXT
            if (!Schema::hasColumn('pages', 'visibility'))      $table->string('visibility')->nullable()->after('template');
            if (!Schema::hasColumn('pages', 'active'))          $table->boolean('active')->default(true)->after('visibility');
            if (!Schema::hasColumn('pages', 'meta_title'))      $table->string('meta_title')->nullable()->after('active');
            if (!Schema::hasColumn('pages', 'meta_description'))$table->text('meta_description')->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $drops = ['description','content','gallery','visibility','active','meta_title','meta_description'];
            foreach ($drops as $col) {
                if (Schema::hasColumn('pages', $col)) $table->dropColumn($col);
            }
        });
    }
};