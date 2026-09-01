<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `projects` table -- Module 5 Phase E CreateProject. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('code', 40);
            $table->string('name');
            $table->foreignUuid('customer_party_id')->nullable()->constrained('business_parties');
            $table->foreignUuid('manager_user_id')->nullable()->constrained('users');
            $table->string('currency', 3);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'code'], 'projects_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
