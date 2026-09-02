<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `organisations` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('taxpayer_id')->unique()->constrained('taxpayers');
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            // Only 'ACTIVE' is ever grep-confirmed set anywhere in the TS source for this column
            // (no UPDATE organisations SET status found either) -- kept as a plain string rather
            // than guessing the rest of the lifecycle's enum values; tighten to ENUM once the
            // full real set is confirmed from source (SQLite's own schema was untyped TEXT too).
            $table->string('status', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisations');
    }
};
