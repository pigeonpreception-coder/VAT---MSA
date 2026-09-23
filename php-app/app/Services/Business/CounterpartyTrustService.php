<?php

namespace App\Services\Business;

use App\Domain\Business\CounterpartyTrustEvaluator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
use App\Models\CounterpartyTrustEvent;
use App\Models\CounterpartyTrustProfile;
use App\Models\CounterpartyVerificationSnapshot;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\CounterpartyTrustGate;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's
 * syntheticallyVerifyBusinessParty -- Issue 3's own labelled test-only
 * verification path (05-security/issue3-counterparty-trust-boundary.md),
 * split out of App\Services\Business\BusinessPartyService the same way
 * App\Services\Business\SupplierVerificationService already was: a
 * self-contained command with its own environment gate, not part of the
 * ordinary create/update/deactivate/search surface that service owns.
 *
 * This is the ONLY path this codebase (or the source) has for a
 * counterparty trust profile to leave PENDING_PROVIDER --
 * `AUTHORITY_VERIFIED` requires the NamRA/ITAS/BIPA provider integration,
 * `BLOCKED -- EXTERNAL DEPENDENCY REQUIRED` until PR-012 and the
 * applicable PR-003 package are signed. It intentionally replays the
 * party's own stored data back as a labelled "authority record" -- it
 * proves workflow, storage, expiry and enforcement only, never that the
 * counterparty actually exists or is registered with any authority.
 */
class CounterpartyTrustService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function syntheticallyVerify(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! CounterpartyTrustGate::syntheticEnabled()) {
            throw new BusinessResourceException('Synthetic counterparty verification is disabled in this environment.', 403);
        }
        $submission = CounterpartyTrustEvaluator::normalizeSyntheticVerification($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'party_id' => $id, 'submission' => $submission]);
        $prior = CommandLedger::prior($actor->id, 'SYNTHETIC_VERIFY_BUSINESS_PARTY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findPartyOrFail($prior, $organisation->id));
        }
        $party = BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $party) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }
        if ($party->status !== 'ACTIVE') {
            throw new RepositoryConflictException('Only an active business party can enter synthetic verification.');
        }
        $trustProfile = CounterpartyTrustProfile::where('business_party_id', $id)->first();
        if (! $trustProfile) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }

        $authority = $submission['authority_record'];
        $evaluation = CounterpartyTrustEvaluator::evaluate([
            'legal_name' => $party->legal_name ?? $party->display_name, 'vat_number' => $party->vat_number,
            'tin' => $party->tin, 'company_registration_number' => $party->company_registration_number,
        ], $authority);

        $checkedAt = now();
        $expiresAt = $checkedAt->copy()->addDay();
        $sourceReference = 'synthetic-counterparty:'.Str::uuid();
        $evidenceHash = CommandLedger::requestHash([
            'source_reference' => $sourceReference, 'business_party_id' => $id, 'authority' => $authority,
            'evaluation' => $evaluation, 'checked_at' => $checkedAt->toISOString(), 'expires_at' => $expiresAt->toISOString(),
        ]);
        $fromStatus = $trustProfile->trust_status;
        $snapshotId = (string) Str::uuid();

        DB::transaction(function () use (
            $trustProfile, $evaluation, $evidenceHash, $sourceReference, $checkedAt, $expiresAt, $authority,
            $snapshotId, $actor, $fromStatus, $organisation, $id, $idempotencyKey, $requestHash, $correlationId,
        ) {
            $trustProfile->update([
                'provider' => 'SYNTHETIC_AUTHORITY', 'provider_environment' => 'SYNTHETIC_TEST',
                'trust_status' => $evaluation['trust_status'], 'tax_registration_status' => $evaluation['tax_registration_status'],
                'vat_verification_status' => $evaluation['vat_verification_status'], 'tin_verification_status' => $evaluation['tin_verification_status'],
                'company_verification_status' => $evaluation['company_verification_status'], 'confidence_bps' => $evaluation['confidence_bps'],
                'evidence_hash' => $evidenceHash, 'source_reference' => $sourceReference, 'reviewed_by' => null,
                'checked_at' => $checkedAt, 'expires_at' => $expiresAt, 'updated_at' => $checkedAt,
            ]);
            CounterpartyVerificationSnapshot::create([
                'id' => $snapshotId, 'trust_profile_id' => $trustProfile->id, 'provider' => 'SYNTHETIC_AUTHORITY',
                'provider_environment' => 'SYNTHETIC_TEST', 'source_reference' => $sourceReference,
                'observed_vat_number' => $authority['vat_number'], 'observed_tin' => $authority['tin'],
                'observed_company_registration_number' => $authority['company_registration_number'],
                'tax_registration_status' => $evaluation['tax_registration_status'], 'trust_status' => $evaluation['trust_status'],
                'confidence_bps' => $evaluation['confidence_bps'], 'matched_fields' => AuditService::canonicalJson($evaluation['matched_fields']),
                'conflicting_fields' => AuditService::canonicalJson($evaluation['conflicting_fields']), 'evidence_hash' => $evidenceHash,
                'checked_at' => $checkedAt, 'expires_at' => $expiresAt, 'recorded_by' => $actor->id,
            ]);
            CounterpartyTrustEvent::create([
                'id' => (string) Str::uuid(), 'trust_profile_id' => $trustProfile->id, 'event_type' => 'CounterpartyTrustEvaluated',
                'from_status' => $fromStatus, 'to_status' => $evaluation['trust_status'], 'reason_code' => $evaluation['reason_code'],
                'evidence_hash' => $evidenceHash, 'actor_id' => $actor->id, 'occurred_at' => $checkedAt,
            ]);
            CommandLedger::record($actor->id, 'SYNTHETIC_VERIFY_BUSINESS_PARTY', $idempotencyKey, $requestHash, 'BUSINESS_PARTY', $id, $checkedAt);
            CommandLedger::outbox('COUNTERPARTY_TRUST', $trustProfile->id, 'CounterpartyTrustEvaluated', $organisation->id, [
                'business_party_id' => $id, 'trust_profile_id' => $trustProfile->id, 'trust_status' => $evaluation['trust_status'],
                'provider_environment' => 'SYNTHETIC_TEST', 'correlation_id' => $correlationId,
            ], $checkedAt);
            AuditService::append($actor, 'COUNTERPARTY_SYNTHETIC_VERIFICATION_RECORDED', 'BUSINESS_PARTY', $id, [
                'organisationId' => $organisation->id, 'trustStatus' => $evaluation['trust_status'], 'reasonCode' => $evaluation['reason_code'],
                'correlationId' => $correlationId, 'nonAuthoritative' => true,
            ], $checkedAt);
        });

        return $this->present($this->findPartyOrFail($id, $organisation->id));
    }

    private function findPartyOrFail(string $id, string $organisationId): BusinessParty
    {
        $party = BusinessParty::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $party) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }

        return $party;
    }

    /**
     * Same presented shape as App\Services\Business\BusinessPartyService's
     * own present() (id/display_name/.../trust fields) -- kept deliberately
     * duplicated rather than reused across services: BusinessPartyService's
     * present() is private and this command's own presented resource is
     * exactly this one party's current state after re-evaluation, with no
     * relationships batching concern of its own.
     *
     * @return array<string, mixed>
     */
    private function present(BusinessParty $party): array
    {
        $trust = CounterpartyTrustProfile::where('business_party_id', $party->id)->first();

        return [
            'id' => $party->id, 'organisation_id' => $party->organisation_id, 'display_name' => $party->display_name,
            'legal_name' => $party->legal_name, 'vat_number' => $party->vat_number, 'tin' => $party->tin,
            'company_registration_number' => $party->company_registration_number,
            'email' => $party->email, 'phone' => $party->phone, 'address' => $party->address, 'status' => $party->status,
            'trust_status' => $trust?->trust_status, 'tax_registration_status' => $trust?->tax_registration_status,
            'vat_verification_status' => $trust?->vat_verification_status, 'tin_verification_status' => $trust?->tin_verification_status,
            'company_verification_status' => $trust?->company_verification_status, 'confidence_bps' => $trust?->confidence_bps,
            'provider_environment' => $trust?->provider_environment,
            'checked_at' => optional($trust?->checked_at)->toISOString(), 'expires_at' => optional($trust?->expires_at)->toISOString(),
        ];
    }
}
