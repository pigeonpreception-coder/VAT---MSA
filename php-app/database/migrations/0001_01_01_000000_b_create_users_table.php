<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merges the TS source's `app_users` (db/runtime.ts) directly onto Laravel's
 * native `users` table rather than keeping them as two separate concepts --
 * `lib/auth.ts`'s buildUserContext resolved role/taxpayer_id/status from
 * app_users on every request; here that's just `Auth::user()`. `role` is a
 * plain string column (not a separate roles table + pivot) because the
 * source's own RBAC (lib/domain/access.ts) is a static role -> permission
 * map keyed by this exact string, not a many-role-per-user model -- one
 * user has exactly one of the 22 roles, same as the original.
 *
 * `external_user_id` is kept (nullable, unique) for the identity_links
 * federated-login architecture (identity_providers/identity_links tables,
 * see the next two migrations) -- per the migration brief, local Laravel
 * auth must work independently of it, so it's optional here, not required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('external_user_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('role', [
                'PILOT_ADMIN', 'TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER',
                'SELLER_ADMIN', 'SELLER_OPERATOR', 'SELLER_VIEWER', 'BUYER_ADMIN', 'BUYER_USER',
                'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER', 'NAMRA_SUPERVISOR', 'NAMRA_SYSTEM_ADMIN',
                'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'DEVELOPER_PARTNER', 'INTERNAL_AUDITOR', 'SECURITY_ANALYST',
            ]);
            $table->foreignUuid('taxpayer_id')->nullable()->constrained('taxpayers');
            $table->enum('status', ['ACTIVE', 'SUSPENDED'])->default('ACTIVE');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
