<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `vat_return_boxes` table -- one row per computed box on a generated return version. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_return_boxes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vat_return_version_id')->constrained('vat_return_versions');
            $table->string('box_code', 30);
            $table->string('label');
            $table->bigInteger('amount_cents');
            $table->unsignedInteger('source_count');
            $table->text('calculation_trace');

            $table->unique(['vat_return_version_id', 'box_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_return_boxes');
    }
};
