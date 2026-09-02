<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A genuine bug caught live by this session's own LicensingTest -- the
 * same same-second timestamp-tie class already found twice before
 * (`communications.occurred_at` in Phase 11 slice 2,
 * `vat_transactions.created_at` in the invoice-lifecycle-completion pass):
 * LicensingService::upgrade's own getLicense() picks an organisation's
 * *current* licence via `ORDER BY effective_from DESC LIMIT 1`, and a
 * licence upgrade issued within the same wall-clock second as the
 * organisation's original licence fixture (well within reach of an
 * automated test, and plausible in production for a scripted onboarding
 * flow too) tied under this codebase's usual bare (0-fractional-second)
 * TIMESTAMP, making "current licence" ambiguous -- reproduced live by
 * LicensingTest's own upgrade-twice test (a second upgrade to the same
 * already-current plan should refuse `LICENSE_PLAN_UNCHANGED`, but
 * instead re-read the stale, closed row and proceeded). Fixed the
 * identical way: microsecond column precision plus OrganisationLicense's
 * own $dateFormat to preserve it end to end through Eloquent's
 * serialization.
 *
 * A second, genuinely separate bug surfaced while fixing the first one --
 * this column is left over as the one NOT NULL timestamp in this table
 * without an explicit DEFAULT (see the original migration's own
 * "one column per table" note, mirrored throughout this codebase).
 * MariaDB's legacy TIMESTAMP auto-initialisation rule quietly attaches
 * BOTH `DEFAULT CURRENT_TIMESTAMP` *and* `ON UPDATE CURRENT_TIMESTAMP` to
 * exactly that one column when no column in the table is given an
 * explicit default -- so a plain `UPDATE organisation_licenses SET
 * effective_to=?` (LicensingService's own SUSPEND/ACTIVATE/RENEW/upgrade
 * writes) was silently *also* stamping `effective_from` to the current
 * moment on every such write, corrupting the very column
 * `ORDER BY effective_from DESC` depends on to find the current licence.
 * Giving it an explicit `DEFAULT CURRENT_TIMESTAMP(6)` (deliberately
 * *without* `ON UPDATE`) removes its "no explicit properties" status and
 * the implicit auto-update along with it -- the app always supplies
 * `effective_from` explicitly on INSERT regardless, so the default itself
 * is never actually relied on, only its side effect of opting this column
 * out of MariaDB's auto-update magic.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE organisation_licenses MODIFY effective_from TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organisation_licenses MODIFY effective_from TIMESTAMP NOT NULL');
    }
};
