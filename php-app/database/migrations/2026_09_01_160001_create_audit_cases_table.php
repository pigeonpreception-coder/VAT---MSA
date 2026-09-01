<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `audit_cases` table -- Module 4 Phase C's
 * real adjacency-list lifecycle (see App\Domain\Compliance\
 * ComplianceValidator::CASE_TRANSITIONS / assertCaseTransition).
 * `suspended_from_status` is what makes RESUME's dynamic target
 * resolvable; `assigned_officer_id` is set only by the ASSIGN action, not
 * auto-assigned to whoever opened the case (a deliberate separation --
 * see the source's own note on "who opened this" vs "who owns it").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('case_number')->unique();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->enum('case_type', ['DESK_REVIEW', 'VAT_AUDIT', 'REFUND_VERIFICATION', 'INVESTIGATION']);
            $table->string('title');
            $table->text('opening_reason');
            $table->string('risk_tier', 10);
            $table->string('status', 30);
            $table->foreignUuid('assigned_officer_id')->nullable()->constrained('users');
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->string('suspended_from_status', 30)->nullable();
            $table->string('appeal_reference')->nullable();
            $table->timestamp('appeal_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_cases');
    }
};
