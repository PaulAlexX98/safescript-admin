<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('nhs_applications', function (Blueprint $t) {
            $t->id();

            $t->string('first_name');
            $t->string('last_name');
            $t->date('dob')->nullable();
            $t->string('gender')->nullable();
            $t->string('nhs_number')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();

            $t->string('address')->nullable();
            $t->string('address1')->nullable();
            $t->string('address2')->nullable();
            $t->string('city')->nullable();
            $t->string('postcode')->nullable();
            $t->string('country')->nullable();

            $t->boolean('use_alt_delivery')->default(false);
            $t->string('delivery_address')->nullable();
            $t->string('delivery_address1')->nullable();
            $t->string('delivery_address2')->nullable();
            $t->string('delivery_city')->nullable();
            $t->string('delivery_postcode')->nullable();
            $t->string('delivery_country')->nullable();

            $t->string('exemption')->nullable();
            $t->string('exemption_number')->nullable();
            $t->date('exemption_expiry')->nullable();

            $t->boolean('consent_patient')->default(false);
            $t->boolean('consent_nomination')->default(false);
            $t->boolean('consent_nomination_explained')->default(false);
            $t->boolean('consent_exemption_signed')->default(false);
            $t->boolean('consent_scr_access')->default(false);

            $t->enum('status', ['pending','approved','rejected'])->default('pending');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();

            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('nhs_applications');
    }
};