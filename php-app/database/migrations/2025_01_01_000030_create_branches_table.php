<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `branches` table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('code');
            $table->string('name');
            $table->text('address');
            $table->string('status', 30);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
            $table->unique(['organisation_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
