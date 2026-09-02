<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `identity_providers` table. Kept per the
 * migration brief for future federated login even though local Laravel
 * auth (users.password) no longer depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider_key')->unique();
            $table->string('display_name');
            $table->string('provider_type', 30);
            $table->string('authority_level', 30);
            $table->string('issuer')->nullable();
            $table->string('status', 30);
            $table->string('configuration_status', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_providers');
    }
};
