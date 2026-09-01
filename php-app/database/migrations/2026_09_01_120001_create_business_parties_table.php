<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `business_parties` table -- the single shared customer/supplier model (relationship is a separate row, see party_relationships, not a column here). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('tin')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('source_system', 40);
            $table->string('source_party_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'source_system', 'source_party_id'], 'business_parties_org_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_parties');
    }
};
