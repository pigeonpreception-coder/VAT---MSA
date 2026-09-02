<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `audit_findings` table -- Module 4 Phase C IssueFinding, a sub-resource creation distinct from a case-status transition. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('audit_case_id')->constrained('audit_cases');
            $table->string('finding_code');
            $table->string('title');
            $table->text('description');
            $table->string('legal_reference')->nullable();
            $table->bigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->foreignUuid('author_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();

            $table->unique(['audit_case_id', 'finding_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_findings');
    }
};
