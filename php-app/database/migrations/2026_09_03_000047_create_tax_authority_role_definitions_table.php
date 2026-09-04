<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authority_role_definitions` table --
 * see 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. `code` (not a generated id) is the
 * primary key, matching the source exactly -- this is a small, fixed
 * catalogue of protected governance-duty roles, not tenant data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authority_role_definitions', function (Blueprint $table) {
            $table->string('code', 60)->primary();
            $table->string('name');
            $table->enum('duty_class', [
                'ONBOARDING_MAKER', 'SECURITY_REVIEW', 'PRIVACY_REVIEW', 'LEGAL_REVIEW', 'INTEGRATION_REVIEW',
                'ACTIVATION_APPROVAL', 'ACCESS_REVIEW', 'SYSTEM_ADMINISTRATION', 'AUDIT',
            ]);
            $table->enum('assurance_required', ['MFA', 'PHISHING_RESISTANT_MFA']);
            $table->boolean('protected')->default(true);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authority_role_definitions');
    }
};
