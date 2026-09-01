<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `access_roles` table -- the 22-role registry
 * (RoleSeeder seeds these rows, one per lib/domain/access.ts ROLE_PERMISSIONS
 * key, code-for-code identical to the source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('audience', 20);
            $table->string('risk_tier', 20);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_roles');
    }
};
