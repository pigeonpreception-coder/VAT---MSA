<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `license_features` table -- a fixed, code-versioned feature catalogue, seed-only like `license_plans`. `feature_key` is its own natural primary key in the source (not a UUID). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_features', function (Blueprint $table) {
            $table->string('feature_key', 60)->primary();
            $table->string('name');
            $table->text('description');
            $table->string('metric_key', 60)->nullable();
            $table->boolean('protected')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_features');
    }
};
