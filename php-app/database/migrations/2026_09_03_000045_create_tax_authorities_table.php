<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `tax_authorities` table -- see
 * 2026_09_03_000043_create_countries_table.php's own doc comment for
 * this module's overall context.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_authorities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('jurisdiction_id')->constrained('tax_jurisdictions');
            $table->string('code', 60);
            $table->string('name');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['jurisdiction_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_authorities');
    }
};
