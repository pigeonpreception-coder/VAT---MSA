<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `organisation_roles` table -- an organisation-defined, least-privilege role assembled from the platform's own `access_permissions` catalogue. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('name');
            $table->text('description');
            $table->unsignedInteger('version')->default(1);
            $table->text('branch_scope')->default('[]');
            $table->bigInteger('approval_limit_cents')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_roles');
    }
};
