<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('nhs_pendings')) {
            return;
        }

        Schema::table('nhs_pendings', function (Blueprint $table) {
            if (!Schema::hasColumn('nhs_pendings', 'status')) {
                $table->string('status')->default('pending')->index();
            }
            if (!Schema::hasColumn('nhs_pendings', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
            if (!Schema::hasColumn('nhs_pendings', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('nhs_pendings', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nhs_pendings')) {
            return;
        }

        Schema::table('nhs_pendings', function (Blueprint $table) {
            if (Schema::hasColumn('nhs_pendings', 'status')) $table->dropColumn('status');
            if (Schema::hasColumn('nhs_pendings', 'completed_at')) $table->dropColumn('completed_at');
            if (Schema::hasColumn('nhs_pendings', 'rejected_at')) $table->dropColumn('rejected_at');
            if (Schema::hasColumn('nhs_pendings', 'rejection_reason')) $table->dropColumn('rejection_reason');
        });
    }
};
