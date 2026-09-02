<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `warehouses` table -- Module 5 Phase D CreateWarehouse unstuck this from seed-only data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('code', 20);
            $table->string('name');
            $table->text('address');
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'code'], 'warehouses_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
