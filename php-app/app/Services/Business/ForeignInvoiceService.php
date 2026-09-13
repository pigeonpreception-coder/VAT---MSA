<?php

namespace App\Services\Business;

use App\Integrations\Etariff\EtariffIntegrationUnavailableException;
use App\Integrations\Etariff\EtariffPort;
use App\Models\ImportRecord;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Support\Str;

/**
 * User's own explicit request: the Foreign Invoices screen must
 * autonomously pull a taxpayer's foreign-invoice declarations from
 * NamRA's E-Tariff border system, so each one is cross-authenticated
 * against the independent duty-paid record captured at the border rather
 * than trusting the taxpayer's own submission alone.
 *
 * Builds on the existing `App\Models\ImportRecord` (customs-import
 * declaration) rather than inventing a parallel table -- see this
 * session's own migration doc comment for why that table's prior
 * read-only-by-design boundary is being explicitly reversed here, at the
 * user's own request, not silently contradicted.
 *
 * Honest about what "autonomous" means given there is no real E-Tariff
 * technical contract today (see `EtariffPort`'s own doc comment): this
 * pulls on-demand, when a user with `imports:manage` triggers it from the
 * Foreign Invoices screen -- there is no queue/cron infrastructure in
 * this codebase to run it unattended (see `outbox_events`' own migration
 * comment on the same point), and building one solely for an integration
 * that cannot be reached yet would be speculative infrastructure, not a
 * real feature. When a real E-Tariff contract exists, wiring this same
 * call into a scheduled command is a small, additive change -- not a
 * redesign.
 *
 * RT-008 (2026-09-13 red-team pass): this action, like `PosApiClientService
 * ::issue()`/`::revoke()`, was originally built without this codebase's own
 * established idempotency-key pattern (`docs/MIGRATION_MATRIX.md`'s
 * "Duplicate-submission hardening" section) -- a live reproduction fired
 * three rapid, same-rendered-form "Pull from E-Tariff" submissions and
 * observed three separate `FOREIGN_INVOICE_PULL_BLOCKED` audit rows for one
 * user action. Harmless *today* only because the integration is fully
 * stubbed (every call is rejected identically); once a real E-Tariff
 * contract exists, an accidental double-click would fire the outbound call
 * twice against a live government system. Now takes the same stable
 * per-form-render key every other write action does and uses
 * `CommandLedger` to recognise an exact replay -- never re-invoking the
 * port or re-auditing for a key already seen, while a genuine second click
 * after a fresh page reload (a new key) still runs normally.
 */
class ForeignInvoiceService
{
    public function __construct(private readonly EtariffPort $etariff) {}

    /** @return array{status: string, pulled: int, message: ?string} */
    public function pullFromEtariff(Organisation $organisation, User $actor, string $idempotencyKey): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $taxpayer = $organisation->taxpayer;
        $now = now();
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id]);
        if (CommandLedger::prior($actor->id, 'FOREIGN_INVOICE_PULL', $idempotencyKey, $requestHash) !== null) {
            return ['status' => 'DUPLICATE_REQUEST_SUPPRESSED', 'pulled' => 0, 'message' => 'This pull request was already processed. Reload the page to try again.'];
        }

        try {
            $declarations = $this->etariff->pullDeclarations([
                'taxpayer_vat_number' => $taxpayer?->vat_number ?? '',
                'tin' => $taxpayer?->tin,
                'correlation_id' => (string) Str::uuid(),
            ]);
        } catch (EtariffIntegrationUnavailableException $e) {
            AuditService::append($actor, 'FOREIGN_INVOICE_PULL_BLOCKED', 'IMPORT_RECORD', $organisation->id, [
                'organisationId' => $organisation->id, 'reason' => $e->getMessage(),
            ], $now);
            CommandLedger::record($actor->id, 'FOREIGN_INVOICE_PULL', $idempotencyKey, $requestHash, 'IMPORT_RECORD', $organisation->id, $now);

            return ['status' => 'BLOCKED_CONFIGURATION', 'pulled' => 0, 'message' => $e->getMessage()];
        }

        foreach ($declarations as $declaration) {
            $existing = ImportRecord::where('organisation_id', $organisation->id)
                ->where('declaration_number', $declaration['declaration_number'])->first();

            $attributes = [
                'organisation_id' => $organisation->id,
                'declaration_number' => $declaration['declaration_number'],
                'customs_office' => $declaration['customs_office'] ?? null,
                'supplier_name' => $declaration['supplier_name'],
                'country_of_origin' => $declaration['country_of_origin'],
                'currency' => $declaration['currency'],
                'customs_value_cents' => $declaration['customs_value_cents'],
                'import_vat_cents' => $declaration['import_vat_cents'],
                'declaration_date' => $declaration['declaration_date'],
                'status' => 'VERIFIED',
                'source' => 'ETARIFF_PULL',
                'etariff_reference' => $declaration['etariff_reference'],
                'verification_status' => 'VERIFIED_VIA_ETARIFF',
                'pulled_at' => $now,
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                ImportRecord::create($attributes + [
                    'id' => (string) Str::uuid(), 'created_by' => $actor->id, 'created_at' => $now,
                ]);
            }
        }

        AuditService::append($actor, 'FOREIGN_INVOICE_PULLED', 'IMPORT_RECORD', $organisation->id, [
            'organisationId' => $organisation->id, 'count' => count($declarations),
        ], $now);
        CommandLedger::record($actor->id, 'FOREIGN_INVOICE_PULL', $idempotencyKey, $requestHash, 'IMPORT_RECORD', $organisation->id, $now);

        return ['status' => 'PULLED', 'pulled' => count($declarations), 'message' => null];
    }
}
