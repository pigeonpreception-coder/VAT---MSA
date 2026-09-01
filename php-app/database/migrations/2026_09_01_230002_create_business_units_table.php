<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `business_units` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('code', 40);
            $table->string('name');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_units');
    }
};
