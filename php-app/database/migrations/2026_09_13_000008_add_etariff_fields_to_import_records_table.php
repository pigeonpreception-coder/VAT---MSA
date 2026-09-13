<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User's own explicit request: link the Foreign Invoices screen to
 * NamRA's E-Tariff border-declarations system so a foreign invoice's own
 * customs value/import VAT can be cross-authenticated against the
 * independent duty-paid record captured at the border, rather than
 * trusting the taxpayer's own submission alone.
 *
 * `import_records` (this migration's own earlier `create_import_records_
 * table.php`) is the closest existing concept -- a customs-import
 * declaration row -- and was deliberately read-only/seed-only until now
 * (see that migration's own doc comment and `OperationsViewController`'s:
 * "a full-repo grep of the TypeScript source confirms `import_records` is
 * only ever read... Building a 'record an import declaration' write
 * command remains out of scope"). This is a genuine, explicit reversal of
 * that documented boundary, not a silent contradiction of it -- the user's
 * own new request is exactly the write command that was previously judged
 * out of scope for having no source precedent.
 *
 * New columns:
 * - `source`: ENUM('MANUAL','ETARIFF_PULL') -- distinguishes the existing
 *   manually-recorded declarations (the seeded demo row, and any future
 *   manual entry) from rows this session's new autonomous pull created/
 *   refreshed from E-Tariff.
 * - `etariff_reference`: E-Tariff's own reference for the declaration --
 *   null for MANUAL rows, always populated for ETARIFF_PULL ones.
 * - `verification_status`: ENUM('UNVERIFIED','VERIFIED_VIA_ETARIFF',
 *   'ETARIFF_PULL_UNAVAILABLE') -- the actual authentication state this
 *   feature exists to establish: whether this row's customs value/import
 *   VAT has been independently cross-checked against the border system,
 *   not just claimed by the taxpayer.
 * - `pulled_at`: when this row was last refreshed from a real E-Tariff
 *   pull -- null until the first successful pull.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_records', function (Blueprint $table) {
            $table->enum('source', ['MANUAL', 'ETARIFF_PULL'])->default('MANUAL')->after('status');
            $table->string('etariff_reference', 100)->nullable()->after('source');
            $table->enum('verification_status', ['UNVERIFIED', 'VERIFIED_VIA_ETARIFF', 'ETARIFF_PULL_UNAVAILABLE'])
                ->default('UNVERIFIED')->after('etariff_reference');
            $table->timestamp('pulled_at')->nullable()->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('import_records', function (Blueprint $table) {
            $table->dropColumn(['source', 'etariff_reference', 'verification_status', 'pulled_at']);
        });
    }
};
