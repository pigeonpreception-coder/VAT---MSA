<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `taxpayer_system_registrations` table --
 * the NamRA e-VAT MS Registered Taxpayer Systems Framework (master prompt
 * section 6): a taxpayer's own ERP/POS/accounting/invoicing system,
 * registered by that taxpayer's own organisation and approved by a
 * national-scope NamRA role. Never ported to this migration until now --
 * see App\Services\TaxpayerSystem\TaxpayerSystemService's own doc comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxpayer_system_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->foreignUuid('taxpayer_id')->constrained('taxpayers');
            $table->string('vat_registration_number');
            $table->string('tin')->nullable();
            $table->string('company_registration_number')->nullable();
            $table->string('system_name');
            $table->string('system_vendor');
            $table->string('system_category', 20);
            $table->string('credential_reference')->nullable();
            $table->string('api_status', 20);
            $table->string('registration_status', 20);
            $table->string('security_status', 20);
            $table->timestamp('last_synchronization_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['organisation_id', 'system_name', 'system_vendor'], 'taxpayer_system_registrations_org_name_vendor_unique');
            $table->index(['organisation_id', 'registration_status'], 'taxpayer_system_registrations_org_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayer_system_registrations');
    }
};
