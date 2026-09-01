<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `organisation_capabilities` table -- the dynamic BUYER/SELLER trading-capability grant (Module 1's "dynamic Buyer/Seller capabilities", not a static role). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisation_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->enum('capability', ['BUYER', 'SELLER']);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_capabilities');
    }
};
