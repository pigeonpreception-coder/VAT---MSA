<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_box_mappings` table -- like its parent
 * `tax_rule_sets`, a read-only reference table with no application write
 * path in the source; DemoSeeder provisions the same four box rows the
 * source seeds for its one pilot rule set (BOX_OUTPUT/BOX_INPUT/BOX_ADJUST/
 * BOX_NET). VatLifecycleService's own return-generation logic hardcodes
 * these same four boxes (mirroring generateVatReturn's own hardcoded
 * `boxes` array in the source, which computes them directly rather than
 * reading this table at generation time either) -- this table exists for
 * completeness/documentation parity with the source schema, not because
 * anything currently reads it at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_box_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tax_rule_set_id')->constrained('tax_rule_sets');
            $table->string('box_code', 30);
            $table->string('label');
            $table->string('source_entry_type', 30);
            $table->string('direction', 20);
            $table->string('formula');
            $table->string('status', 20);

            $table->unique(['tax_rule_set_id', 'box_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_box_mappings');
    }
};
