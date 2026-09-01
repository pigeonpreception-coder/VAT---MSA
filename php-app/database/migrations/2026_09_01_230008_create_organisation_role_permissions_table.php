<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `organisation_role_permissions` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_role_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_role_id')->constrained('organisation_roles');
            $table->string('permission_code', 60);
            $table->foreign('permission_code')->references('code')->on('access_permissions');
            $table->string('record_scope', 20);
            $table->string('effect', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_role_id', 'permission_code'], 'org_role_permissions_role_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_role_permissions');
    }
};
