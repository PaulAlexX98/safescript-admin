<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_pharmacist')) {
                $table->boolean('is_pharmacist')->default(false)->after('password');
            }
            if (! Schema::hasColumn('users', 'pharmacist_display_name')) {
                $table->string('pharmacist_display_name')->nullable()->after('is_pharmacist');
            }
            if (! Schema::hasColumn('users', 'gphc_number')) {
                $table->string('gphc_number')->nullable()->after('pharmacist_display_name');
            }
            if (! Schema::hasColumn('users', 'signature_path')) {
                $table->string('signature_path')->nullable()->after('gphc_number');
            }
            if (! Schema::hasColumn('users', 'consultation_defaults')) {
                $table->json('consultation_defaults')->nullable()->after('signature_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'consultation_defaults')) $table->dropColumn('consultation_defaults');
            if (Schema::hasColumn('users', 'signature_path')) $table->dropColumn('signature_path');
            if (Schema::hasColumn('users', 'gphc_number')) $table->dropColumn('gphc_number');
            if (Schema::hasColumn('users', 'pharmacist_display_name')) $table->dropColumn('pharmacist_display_name');
            if (Schema::hasColumn('users', 'is_pharmacist')) $table->dropColumn('is_pharmacist');
        });
    }
};