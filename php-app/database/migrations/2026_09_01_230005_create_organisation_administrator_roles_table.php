<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `organisation_administrator_roles` table -- a fixed, code-versioned catalogue, seed-only like `license_features`. `code` is its own natural primary key in the source, not a UUID. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_administrator_roles', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('maximum_scope', 40);
            $table->boolean('protected')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_administrator_roles');
    }
};
