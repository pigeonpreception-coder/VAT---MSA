<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_jurisdictions` table -- see
 * 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context. `id` is declared `uuid()` (a `CHAR(36)`
 * column, matching this migration's own established `tax_rule_sets.id`
 * precedent) but holds the source's own stable, human-readable seed IDs
 * (e.g. 'tax-jurisdiction-na-national'), not generated UUIDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_jurisdictions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 4);
            $table->foreign('country_code')->references('code')->on('countries');
            $table->string('code', 60);
            $table->string('name');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['country_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_jurisdictions');
    }
};
