<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `registration_applications` table -- a
 * taxpayer/organisation does not exist until a decision APPROVEs this row
 * (see App\Services\Identity\RegistrationService::decide).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key');
            $table->string('request_hash', 64);
            $table->string('vat_number');
            $table->string('tin');
            $table->string('company_registration_number')->nullable();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->enum('taxpayer_type', ['PRIVATE_COMPANY', 'CLOSE_CORPORATION', 'SOLE_PROPRIETOR', 'PARTNERSHIP', 'TRUST', 'NON_PROFIT', 'PUBLIC_ENTITY', 'OTHER']);
            $table->enum('return_frequency', ['MONTHLY', 'BIMONTHLY', 'QUARTERLY', 'ANNUAL']);
            $table->text('address');
            $table->string('email');
            $table->enum('status', ['PENDING_VERIFICATION', 'UNDER_REVIEW', 'VERIFIED', 'APPROVED', 'REJECTED']);
            $table->string('verification_source', 20);
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();

            $table->unique(['submitted_by', 'idempotency_key'], 'registration_applications_submitted_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_applications');
    }
};
