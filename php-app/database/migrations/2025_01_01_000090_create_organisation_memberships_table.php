<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `organisation_memberships` table -- the row
 * that ties a user to an organisation with a role and (optionally) a
 * branch. This is the multi-tenancy anchor: every organisation-scoped
 * Eloquent query must join/filter through this, never trust a bare
 * organisation_id from a request (see Phase 7's OrganisationScope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role_code', 40);
            $table->foreign('role_code')->references('code')->on('access_roles');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('status', 20);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_memberships');
    }
};
