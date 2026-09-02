<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `saas_providers` table -- Module 10 Phase C
 * SaaS marketplace provider onboarding (SaaSProvider/Application/
 * EnvironmentApproval), a `platform-repository.ts` sub-domain not yet
 * ported. No command references this table yet in this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_key', 60)->unique();
            $table->string('legal_name');
            $table->string('contact_email');
            $table->string('category', 40);
            $table->string('status', 20);
            $table->foreignUuid('registered_by')->constrained('users');
            $table->timestamp('registered_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_providers');
    }
};
