<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ported from db/runtime.ts's `chart_of_accounts` table -- Module 5 Phase C CreateAccount unstuck this from seed-only data. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('code', 20);
            $table->string('name');
            $table->enum('account_type', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']);
            $table->string('currency', 3);
            $table->string('control_type', 40)->nullable();
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['organisation_id', 'code'], 'chart_of_accounts_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
