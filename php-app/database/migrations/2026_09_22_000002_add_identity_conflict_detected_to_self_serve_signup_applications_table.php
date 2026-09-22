<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a VAT/TIN enumeration channel found while deploying self-serve
 * signup (PR #58): SignupService::submit() used to throw a distinct
 * RepositoryConflictException (HTTP 409, no row written) whenever the
 * submitted vat_number/tin already matched an existing taxpayer, an
 * in-progress registration application, or another pending self-serve
 * application -- an anonymous caller could tell a real VAT number/TIN
 * apart from an unused one purely from the response shape, with no
 * authentication and no rate-limit exemption needed to do it (unlike the
 * header-spoofing rate-limit bypass this same review found and fixed in
 * PR #61, this one doesn't require bypassing anything -- the signal is in
 * the *documented, intended* response of a correctly-functioning,
 * correctly-rate-limited endpoint).
 *
 * The fix (App\Services\Signup\SignupService) is to stop rejecting a
 * conflicting submission at all -- every submission that passes
 * validation now gets the identical 202 Accepted response and writes a
 * real self_serve_signup_applications row, whether or not the identity
 * conflicts with something already in the system. This column is where
 * that conflict is still recorded -- for whoever eventually reviews these
 * applications, not for the anonymous submitter: App\Services\Signup\
 * SignupService::present() (what the HTTP response actually echoes back)
 * never reads it, so it carries no information back to the caller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_serve_signup_applications', function (Blueprint $table) {
            $table->boolean('identity_conflict_detected')->default(false)->after('taxpayer_verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('self_serve_signup_applications', function (Blueprint $table) {
            $table->dropColumn('identity_conflict_detected');
        });
    }
};
