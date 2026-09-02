<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from db/runtime.ts's `subscriptions` table. Grepped across every
 * .ts file under lib/ before writing this migration, same as
 * `tax_rule_sets`/`vat_periods` before it: this table has NO application
 * write path anywhere in the source at all -- a subscription (and an
 * organisation's very first licence) is provisioned out of band, not
 * through any command this codebase itself exposes. VatLifecycleService's
 * own doc comment on the identical pattern applies here unchanged:
 * LicensingService only ever reads subscriptions (via the licence row it
 * joins to) and never creates one; test fixtures provision one directly,
 * exactly as the source's own demo seed does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('organisations');
            $table->string('provider', 40);
            $table->string('provider_reference');
            $table->string('status', 20);
            $table->timestamp('activated_at')->nullable();
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
