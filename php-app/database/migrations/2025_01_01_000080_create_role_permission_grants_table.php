<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `role_permission_grants` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role_code', 40);
            $table->foreign('role_code')->references('code')->on('access_roles');
            $table->string('permission_code', 60);
            $table->foreign('permission_code')->references('code')->on('access_permissions');
            $table->string('effect', 10);
            $table->text('conditions');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['role_code', 'permission_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_grants');
    }
};
