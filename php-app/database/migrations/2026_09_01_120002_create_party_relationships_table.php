<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `party_relationships` table -- CUSTOMER/SUPPLIER is a dynamic, revocable grant on a business_parties row, never a fixed column on it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('party_id')->constrained('business_parties');
            $table->enum('relationship', ['CUSTOMER', 'SUPPLIER']);
            $table->string('status', 20);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'party_id', 'relationship'], 'party_relationships_org_party_rel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_relationships');
    }
};
