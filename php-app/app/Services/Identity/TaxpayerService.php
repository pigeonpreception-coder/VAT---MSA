<?php

namespace App\Services\Identity;

use App\Domain\Identity\TaxpayerIdentifierValidator;
use App\Exceptions\IdentityValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Integrations\Itas\ItasIdentityPort;
use App\Integrations\Itas\ItasIntegrationUnavailableException;
use App\Models\OutboxEvent;
use App\Models\Taxpayer;
use App\Models\TaxpayerIdentifier;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ported from lib/data/identity-repository.ts's suspendTaxpayer. Flips
 * taxpayers.vat_status to SUSPENDED -- Taxpayer::isActive() and every
 * counterparty-resolution query elsewhere must filter on vat_status='ACTIVE'
 * for this to have real enforcement effect, matching the source's own note.
 * Idempotent: suspending an already-suspended taxpayer is a no-op.
 */
class TaxpayerService
{
    private const CORRECTABLE_IDENTIFIER_TYPES = ['VAT_NUMBER', 'TIN'];

    public function __construct(private readonly ItasIdentityPort $itas) {}

    /**
     * Ported from lib/data/repository.ts's listTaxpayers -- the source's
     * own "Canonical taxpayer registry" page (app/taxpayers/page.tsx),
     * which existed in source with no JSON API route at all (no
     * `app/api/v1/taxpayers/route.ts`), matching a handful of other
     * page-only snapshot reads already ported this way elsewhere in this
     * migration.
     *
     * Deliberately unscoped, matching the source's own query exactly: no
     * `TenantScope` filter here, even though the page's own gate is just
     * `taxpayers:read` -- confirmed against Permissions::ROLE_PERMISSIONS
     * that this permission is held broadly, including by
     * TAXPAYER_OWNER/ADMIN/ACCOUNTANT, not NAMRA-only. This is the
     * source's own design (a shared canonical directory of VAT numbers/
     * TINs/aggregate counts, not per-taxpayer financial detail), not an
     * oversight to "fix" with invented scoping the source never applies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $capabilities = DB::table('organisation_capabilities as c')
            ->join('organisations as o', 'o.id', '=', 'c.organisation_id')
            ->selectRaw("GROUP_CONCAT(c.capability ORDER BY c.capability SEPARATOR ',')")
            ->whereColumn('o.taxpayer_id', 'taxpayers.id')
            ->where('o.status', 'ACTIVE')->where('c.status', 'ACTIVE')
            ->where('c.effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>', now()));

        $organisationId = DB::table('organisations')->select('id')
            ->whereColumn('taxpayer_id', 'taxpayers.id')->where('status', 'ACTIVE')->limit(1);

        $transactionCount = DB::table('invoices')->selectRaw('COUNT(*)')
            ->where(fn ($q) => $q->whereColumn('supplier_taxpayer_id', 'taxpayers.id')->orWhereColumn('customer_taxpayer_id', 'taxpayers.id'));

        $outputTax = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(amount_cents),0)')
            ->whereColumn('taxpayer_id', 'taxpayers.id')->where('entry_type', 'OUTPUT_VAT');

        $inputTax = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(amount_cents),0)')
            ->whereColumn('taxpayer_id', 'taxpayers.id')->where('entry_type', 'INPUT_VAT');

        return DB::table('taxpayers')
            ->select('taxpayers.*')
            ->addSelect(['organisation_id' => $organisationId, 'capabilities' => $capabilities])
            ->addSelect(['transaction_count' => $transactionCount, 'output_tax_cents' => $outputTax, 'input_tax_cents' => $inputTax])
            ->orderBy('legal_name')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array{taxpayerId: string, vatStatus: string} */
    public function suspend(User $actor, string $taxpayerId, string $reason, string $correlationId): array
    {
        $taxpayer = Taxpayer::find($taxpayerId);
        if (! $taxpayer) {
            throw ValidationException::withMessages(['taxpayer_id' => 'The taxpayer does not exist.']);
        }
        if ($taxpayer->vat_status === 'SUSPENDED') {
            return ['taxpayerId' => $taxpayer->id, 'vatStatus' => 'SUSPENDED'];
        }

        $now = now();
        $previousStatus = $taxpayer->vat_status;

        DB::transaction(function () use ($taxpayer, $reason, $actor, $now, $correlationId, $previousStatus) {
            $taxpayer->update(['vat_status' => 'SUSPENDED']);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'TAXPAYER', 'aggregate_id' => $taxpayer->id,
                'event_type' => 'TaxpayerSuspended', 'event_version' => 1, 'partition_key' => $taxpayer->id,
                'payload' => AuditService::canonicalJson(['taxpayer_id' => $taxpayer->id, 'reason' => $reason, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'TAXPAYER_SUSPENDED', 'TAXPAYER', $taxpayer->id, ['reason' => $reason, 'previousStatus' => $previousStatus], $now);
        });

        return ['taxpayerId' => $taxpayerId, 'vatStatus' => 'SUSPENDED'];
    }

    /**
     * Ported from lib/data/identity-repository.ts's correctTaxpayerIdentifier.
     * Module 1 Taxpayer IdentifierVersion / correction path. Statutory
     * identity records are never overwritten in place: correcting a VAT
     * number or TIN supersedes the current taxpayer_identifiers row
     * (status SUPERSEDED, effective_to set) and inserts a new versioned row
     * linked back via previous_version_id, rather than mutating
     * identifier_value in place. Also keeps taxpayers.vat_number/tin in
     * sync, since those denormalized columns are what actually gets read
     * elsewhere. Scoped to VAT_NUMBER/TIN only, the only identifier types
     * this codebase currently issues.
     *
     * @return array{taxpayerId: string, identifierType: string, previousIdentifierId: string, newIdentifierId: string, identifierValue: string, version: int}
     */
    public function correctIdentifier(User $actor, string $taxpayerId, string $identifierId, array $payload, string $correlationId): array
    {
        $correction = TaxpayerIdentifierValidator::correction($payload);

        $current = TaxpayerIdentifier::where('id', $identifierId)->where('taxpayer_id', $taxpayerId)->first();
        if (! $current) {
            throw new IdentityValidationException([['code' => 'IDENTIFIER_NOT_FOUND', 'path' => '/identifier_id', 'message' => 'The identifier does not exist for this taxpayer.']]);
        }
        if ($current->status !== 'ACTIVE') {
            throw new IdentityValidationException([['code' => 'IDENTIFIER_NOT_ACTIVE', 'path' => '/identifier_id', 'message' => "This identifier is currently {$current->status}; correct the current active version instead."]]);
        }
        if (! in_array($current->identifier_type, self::CORRECTABLE_IDENTIFIER_TYPES, true)) {
            throw new IdentityValidationException([['code' => 'IDENTIFIER_TYPE_NOT_CORRECTABLE', 'path' => '/identifier_id', 'message' => "{$current->identifier_type} identifiers cannot be corrected via this command."]]);
        }
        if ($current->identifier_value === $correction['identifier_value']) {
            throw new IdentityValidationException([['code' => 'IDENTIFIER_UNCHANGED', 'path' => '/identifier_value', 'message' => 'The corrected value is identical to the current value.']]);
        }
        $duplicateIdentifier = TaxpayerIdentifier::where('identifier_type', $current->identifier_type)
            ->where('identifier_value', $correction['identifier_value'])->where('country', $current->country)
            ->where('status', 'ACTIVE')->exists();
        if ($duplicateIdentifier) {
            throw new RepositoryConflictException('Another active taxpayer already holds this identifier value.');
        }
        $taxpayerColumn = $current->identifier_type === 'VAT_NUMBER' ? 'vat_number' : 'tin';
        $duplicateTaxpayer = Taxpayer::where($taxpayerColumn, $correction['identifier_value'])->where('id', '!=', $taxpayerId)->exists();
        if ($duplicateTaxpayer) {
            throw new RepositoryConflictException('Another taxpayer already uses this identifier value.');
        }

        $now = now();
        $newId = (string) Str::uuid();
        $nextVersion = $current->version + 1;

        DB::transaction(function () use ($current, $correction, $taxpayerId, $taxpayerColumn, $now, $newId, $nextVersion, $actor, $correlationId) {
            // Affected-row guard (RT punch list #8 pattern): the ACTIVE
            // pre-check above ran before this transaction, unguarded
            // against a concurrent correction on the same identifier --
            // source itself has no such guard, but every other versioned
            // state transition in this port carries one.
            $updated = TaxpayerIdentifier::where('id', $current->id)->where('status', 'ACTIVE')
                ->update(['status' => 'SUPERSEDED', 'effective_to' => $now]);
            if ($updated === 0) {
                throw new RepositoryConflictException("Identifier {$current->id} was changed by another action; reload and try again.");
            }
            TaxpayerIdentifier::create([
                'id' => $newId, 'taxpayer_id' => $taxpayerId, 'identifier_type' => $current->identifier_type,
                'identifier_value' => $correction['identifier_value'], 'country' => $current->country, 'status' => 'ACTIVE',
                'source' => 'MANUAL_CORRECTION', 'verified_at' => null, 'created_at' => $now,
                'version' => $nextVersion, 'effective_from' => $now, 'effective_to' => null,
                'previous_version_id' => $current->id,
            ]);
            Taxpayer::where('id', $taxpayerId)->update([$taxpayerColumn => $correction['identifier_value']]);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'TAXPAYER', 'aggregate_id' => $taxpayerId,
                'event_type' => 'TaxpayerIdentifierCorrected', 'event_version' => 1, 'partition_key' => $taxpayerId,
                'payload' => AuditService::canonicalJson([
                    'taxpayer_id' => $taxpayerId, 'identifier_type' => $current->identifier_type,
                    'previous_identifier_id' => $current->id, 'new_identifier_id' => $newId, 'correlation_id' => $correlationId,
                ]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'TAXPAYER_IDENTIFIER_CORRECTED', 'TAXPAYER_IDENTIFIER', $newId, [
                'taxpayerId' => $taxpayerId, 'identifierType' => $current->identifier_type,
                'previousValue' => $current->identifier_value, 'newValue' => $correction['identifier_value'],
                'reason' => $correction['reason'], 'previousIdentifierId' => $current->id,
            ], $now);
        });

        return [
            'taxpayerId' => $taxpayerId, 'identifierType' => $current->identifier_type, 'previousIdentifierId' => $current->id,
            'newIdentifierId' => $newId, 'identifierValue' => $correction['identifier_value'], 'version' => $nextVersion,
        ];
    }

    /**
     * Ported from lib/data/identity-repository.ts's verifyTaxpayerIdentifiers
     * -- a standalone command re-triggerable any time after registration.
     * RegistrationService::submit already records one AWAITING_PROVIDER_
     * CONTRACT attempt at intake; this lets it be retried once ITAS becomes
     * available without resubmitting an entire new registration. Calls the
     * same ItasIdentityPort RegistrationService already calls; today that
     * always throws ItasIntegrationUnavailableException (see that port's
     * own doc comment), caught here and reported honestly as
     * AWAITING_PROVIDER_CONTRACT rather than silently swallowed or faked
     * into a success.
     *
     * @return array{taxpayerId: string, provider: string, status: string, checkedAt: string, requestReference: ?string}
     */
    public function verifyIdentifiers(User $actor, string $taxpayerId, string $correlationId): array
    {
        $taxpayer = Taxpayer::find($taxpayerId);
        if (! $taxpayer) {
            throw new IdentityValidationException([['code' => 'TAXPAYER_NOT_FOUND', 'path' => '/taxpayer_id', 'message' => 'The taxpayer does not exist.']]);
        }
        TenantScope::requireTaxpayer($actor, $taxpayer->id);

        $now = now();

        try {
            $result = $this->itas->verifyTaxpayer([
                'vat_number' => $taxpayer->vat_number, 'tin' => $taxpayer->tin, 'company_registration_number' => null,
                'correlation_id' => $correlationId,
            ]);
            DB::transaction(function () use ($taxpayerId, $result, $actor, $correlationId) {
                TaxpayerIdentifier::where('taxpayer_id', $taxpayerId)->whereIn('identifier_type', ['VAT_NUMBER', 'TIN'])
                    ->where('status', 'ACTIVE')->update(['verified_at' => $result['checked_at']]);
                OutboxEvent::create([
                    'id' => (string) Str::uuid(), 'aggregate_type' => 'TAXPAYER', 'aggregate_id' => $taxpayerId,
                    'event_type' => 'TaxpayerVerified', 'event_version' => 1, 'partition_key' => $taxpayerId,
                    'payload' => AuditService::canonicalJson([
                        'taxpayer_id' => $taxpayerId, 'source' => 'ITAS', 'verified_at' => $result['checked_at'],
                        'request_reference' => $result['request_reference'], 'correlation_id' => $correlationId,
                    ]),
                    'status' => 'PENDING', 'occurred_at' => now(), 'available_at' => now(),
                ]);
                AuditService::append($actor, 'TAXPAYER_IDENTIFIERS_VERIFIED', 'TAXPAYER', $taxpayerId, [
                    'provider' => 'ITAS', 'requestReference' => $result['request_reference'],
                ], now());
            });

            return ['taxpayerId' => $taxpayerId, 'provider' => 'ITAS', 'status' => 'VERIFIED', 'checkedAt' => $result['checked_at'], 'requestReference' => $result['request_reference']];
        } catch (ItasIntegrationUnavailableException) {
            AuditService::append($actor, 'TAXPAYER_IDENTIFIER_VERIFICATION_ATTEMPTED', 'TAXPAYER', $taxpayerId, [
                'provider' => 'ITAS', 'outcome' => 'AWAITING_PROVIDER_CONTRACT',
            ], $now);

            return ['taxpayerId' => $taxpayerId, 'provider' => 'ITAS', 'status' => 'AWAITING_PROVIDER_CONTRACT', 'checkedAt' => $now->toIso8601String(), 'requestReference' => null];
        }
    }
}
