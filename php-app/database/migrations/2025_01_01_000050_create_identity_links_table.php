<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `identity_links` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('provider_id')->constrained('identity_providers');
            $table->string('subject');
            $table->string('email_at_link')->nullable();
            $table->string('assurance_level', 40);
            $table->string('status', 30);
            $table->timestamp('linked_at');
            $table->timestamp('last_authenticated_at')->nullable();
            $table->unique(['provider_id', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_links');
    }
};
