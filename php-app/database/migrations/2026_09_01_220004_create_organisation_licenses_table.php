<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `organisation_licenses` table. An
 * organisation's *first* row here is out-of-band seed data (see
 * `subscriptions`'s own doc comment -- the same gap, together); every
 * *subsequent* row is a real, application-written one --
 * LicensingService::upgradeLicense closes the current row
 * (`effective_to`) and inserts a new one on the target plan (a versioned
 * history of plan changes, never an in-place row mutation for that
 * command), and changeLicenseState mutates `state`/`state_version` on the
 * current row in place for Activate/Suspend/Renew.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->foreignUuid('license_plan_id')->constrained('license_plans');
            $table->string('state', 20);
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->string('retention_policy', 60);
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['organisation_id', 'state', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_licenses');
    }
};
