<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `import_records` table -- business-
 * repository.ts's customs-import declaration record. A full-repo grep
 * before writing this migration confirmed it is only ever read (by
 * `getBusinessPlatformSnapshot`, itself already deferred -- see Phase 10's
 * own completion note in docs/MIGRATION_MATRIX.md) and never written by
 * any command; `documents:read`/`documents:upload`-adjacent `imports:read`/
 * `imports:manage` permissions already exist in App\Support\Access\
 * Permissions for whichever future command creates rows here.
 * `evidence_document_id` has no REFERENCES clause in the source either --
 * mirrored as a plain nullable column, not a foreign key the source
 * doesn't declare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('declaration_number', 100);
            $table->string('customs_office')->nullable();
            $table->string('supplier_name');
            $table->string('country_of_origin', 2);
            $table->string('currency', 3);
            $table->bigInteger('customs_value_cents');
            $table->bigInteger('import_vat_cents');
            $table->date('declaration_date');
            $table->uuid('evidence_document_id')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'declaration_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_records');
    }
};
