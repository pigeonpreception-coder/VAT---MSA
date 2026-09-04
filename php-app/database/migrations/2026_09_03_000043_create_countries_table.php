<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `countries` table -- the first of twelve
 * new tables for the Authority Governance module (NamRA Administration
 * portal, `lib/data/authority-governance-repository.ts`). `code` is the
 * primary key and stays a plain string (ISO 3166-1 alpha-2, e.g. 'NA'),
 * not a UUID -- matching the source's own seed data exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->string('code', 4)->primary();
            $table->string('iso3_code', 3)->unique();
            $table->string('name');
            $table->string('currency_code', 3);
            $table->string('status', 20);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
