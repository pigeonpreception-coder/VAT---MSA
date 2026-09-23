<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's business_parties.company_registration_number
 * column (added there by its own ALTER TABLE migration entry). Alongside
 * vat_number/tin as a third independent business identifier -- see
 * App\Domain\Business\BusinessValidator::party() and
 * App\Domain\Business\CounterpartyTrustEvaluator for where it is validated
 * and reconciled against a counterparty trust profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_parties', function (Blueprint $table) {
            $table->string('company_registration_number')->nullable()->after('tin');
        });
    }

    public function down(): void
    {
        Schema::table('business_parties', function (Blueprint $table) {
            $table->dropColumn('company_registration_number');
        });
    }
};
