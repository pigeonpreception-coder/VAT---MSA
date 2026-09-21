<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `self_serve_signup_applications` table --
 * the self-serve commercial SaaS signup channel (lib/domain/signup.ts,
 * lib/data/signup-repository.ts): an unauthenticated applicant submits
 * one PENDING_VERIFICATION application naming a COMMERCIAL_SAAS licence
 * plan; no account, payment, subscription or licence is ever activated by
 * this alone. `promoted_registration_application_id` and the
 * UNDER_REVIEW/REJECTED/APPROVED_FOR_PROVISIONING states exist in
 * source's own schema, but source names no command anywhere that ever
 * reads or writes them (confirmed by a full-repo grep for
 * "self_serve_signup"/"SelfServeSignup" across lib/app/db finding only
 * SubmitSelfServeSignup and the read-only, routeless
 * listSelfServeSignupApplications) -- that review/promotion workflow was
 * never actually built even in source, so this migration ports the
 * column shape faithfully without inventing a command for it either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_serve_signup_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('public_reference')->unique();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->string('applicant_name');
            $table->string('applicant_role', 30);
            $table->string('contact_email');
            $table->string('identity_provider', 30)->nullable();
            $table->string('identity_subject_hash', 80)->nullable();
            $table->string('onboarding_path', 20)->default('COMPANY_ADMIN');
            $table->string('country_code', 2)->default('NA');
            $table->foreignUuid('requested_plan_id')->constrained('license_plans');
            $table->string('vat_number');
            $table->string('tin');
            $table->string('company_registration_number')->nullable();
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->string('taxpayer_type', 30);
            $table->string('return_frequency', 20);
            $table->text('address');
            $table->string('terms_version', 20);
            $table->string('privacy_notice_version', 20);
            $table->timestamp('authority_attested_at');
            $table->timestamp('terms_accepted_at');
            $table->timestamp('privacy_notice_accepted_at');
            $table->string('status', 30);
            $table->string('identity_status', 30);
            $table->string('taxpayer_verification_status', 30);
            $table->string('licence_status', 20)->default('NOT_ACTIVATED');
            $table->uuid('promoted_registration_application_id')->nullable();
            $table->foreign('promoted_registration_application_id', 'self_serve_signup_promoted_reg_app_foreign')
                ->references('id')->on('registration_applications');
            $table->timestamp('submitted_at');

            $table->unique(['contact_email', 'idempotency_key'], 'self_serve_signup_applications_email_idempotency_unique');
            $table->index(['status', 'submitted_at']);
            $table->index(['vat_number', 'tin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_serve_signup_applications');
    }
};
