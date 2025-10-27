<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('raf_form_id')->nullable()->constrained('clinic_forms')->nullOnDelete();
            $table->foreignId('consultation_advice_form_id')->nullable()->constrained('clinic_forms')->nullOnDelete();
            $table->foreignId('pharmacist_declaration_form_id')->nullable()->constrained('clinic_forms')->nullOnDelete();
            $table->foreignId('clinical_notes_form_id')->nullable()->constrained('clinic_forms')->nullOnDelete();
            $table->foreignId('reorder_form_id')->nullable()->constrained('clinic_forms')->nullOnDelete();
            // intentionally NO patient_declaration_form_id (we're removing that slot)
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raf_form_id');
            $table->dropConstrainedForeignId('consultation_advice_form_id');
            $table->dropConstrainedForeignId('pharmacist_declaration_form_id');
            $table->dropConstrainedForeignId('clinical_notes_form_id');
            $table->dropConstrainedForeignId('reorder_form_id');
        });
    }
};