<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
use App\Models\CounterpartyTrustEvent;
use App\Models\CounterpartyTrustProfile;
use App\Models\PartyRelationship;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's createBusinessParty/
 * updateBusinessParty/deactivateBusinessParty/searchBusinessParties --
 * Module 5 Phase A. The shared customer/supplier model: relationships are
 * dynamic, revocable grants (party_relationships), never a fixed column.
 */
class BusinessPartyService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $party = BusinessValidator::party($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'party' => $party]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_BUSINESS_PARTY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $this->assertIdentifiersAvailable($organisation->id, $party);

        $id = (string) Str::uuid();
        $trustProfileId = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($party, $organisation, $actor, $id, $trustProfileId, $now, $idempotencyKey, $requestHash, $correlationId) {
            BusinessParty::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'display_name' => $party['display_name'],
                'legal_name' => $party['legal_name'], 'vat_number' => $party['vat_number'], 'tin' => $party['tin'],
                'company_registration_number' => $party['company_registration_number'],
                'email' => $party['email'], 'phone' => $party['phone'], 'address' => $party['address'],
                'source_system' => 'LOCAL', 'source_party_id' => null, 'status' => 'ACTIVE',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($party['relationships'] as $relationship) {
                PartyRelationship::create([
                    'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $id,
                    'relationship' => $relationship, 'status' => 'ACTIVE', 'effective_from' => $now,
                    'effective_to' => null, 'created_at' => $now,
                ]);
            }
            // Ported from createBusinessParty's own counterparty_trust_profiles
            // INSERT -- creation is intake only, never transaction-eligible: the
            // party is not trusted for new business until it clears
            // App\Support\Business\CounterpartyTrustGate, which every new
            // party starts out failing (PENDING_PROVIDER). See
            // 05-security/issue3-counterparty-trust-boundary.md.
            CounterpartyTrustProfile::create([
                'id' => $trustProfileId, 'business_party_id' => $id, 'provider' => 'ITAS_BIPA',
                'provider_environment' => 'CONTRACT_PENDING', 'trust_status' => 'PENDING_PROVIDER',
                'tax_registration_status' => 'UNKNOWN',
                'vat_verification_status' => $party['vat_number'] ? 'PENDING' : 'NOT_PROVIDED',
                'tin_verification_status' => $party['tin'] ? 'PENDING' : 'NOT_PROVIDED',
                'company_verification_status' => $party['company_registration_number'] ? 'PENDING' : 'NOT_PROVIDED',
                'confidence_bps' => 0, 'evidence_hash' => null, 'source_reference' => null,
                'requested_by' => $actor->id, 'reviewed_by' => null, 'checked_at' => null, 'expires_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            CounterpartyTrustEvent::create([
                'id' => (string) Str::uuid(), 'trust_profile_id' => $trustProfileId, 'event_type' => 'CounterpartyVerificationRequested',
                'from_status' => null, 'to_status' => 'PENDING_PROVIDER', 'reason_code' => 'AUTHORITY_PROVIDER_CONTRACT_REQUIRED',
                'evidence_hash' => null, 'actor_id' => $actor->id, 'occurred_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_BUSINESS_PARTY', $idempotencyKey, $requestHash, 'BUSINESS_PARTY', $id, $now);
            CommandLedger::outbox('BUSINESS_PARTY', $id, 'BusinessPartyCreated', $organisation->id, [
                'party_id' => $id, 'organisation_id' => $organisation->id, 'relationships' => $party['relationships'], 'correlation_id' => $correlationId,
            ], $now);
            CommandLedger::outbox('COUNTERPARTY_TRUST', $trustProfileId, 'CounterpartyVerificationRequested', $organisation->id, [
                'business_party_id' => $id, 'trust_profile_id' => $trustProfileId, 'status' => 'PENDING_PROVIDER',
                'provider_environment' => 'CONTRACT_PENDING', 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'BUSINESS_PARTY_CREATED', 'BUSINESS_PARTY', $id, [
                'organisationId' => $organisation->id, 'relationships' => $party['relationships'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function update(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $party = BusinessValidator::party($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'party_id' => $id, 'party' => $party]);
        $prior = CommandLedger::prior($actor->id, 'UPDATE_BUSINESS_PARTY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $existing) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }
        if ($existing->status !== 'ACTIVE') {
            throw new RepositoryConflictException('An inactive business party cannot be edited. Create a new active relationship record if trading resumes.');
        }
        $this->assertIdentifiersAvailable($organisation->id, $party, $id);
        $identityChanged = ($existing->legal_name ?? '') !== ($party['legal_name'] ?? '')
            || ($existing->vat_number ?? '') !== ($party['vat_number'] ?? '')
            || ($existing->tin ?? '') !== ($party['tin'] ?? '')
            || ($existing->company_registration_number ?? '') !== ($party['company_registration_number'] ?? '');
        $trustProfile = CounterpartyTrustProfile::where('business_party_id', $id)->first();

        $now = now();

        DB::transaction(function () use ($party, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId, $identityChanged, $trustProfile) {
            BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->update([
                'display_name' => $party['display_name'], 'legal_name' => $party['legal_name'], 'vat_number' => $party['vat_number'],
                'tin' => $party['tin'], 'company_registration_number' => $party['company_registration_number'],
                'email' => $party['email'], 'phone' => $party['phone'], 'address' => $party['address'],
                'updated_at' => $now,
            ]);
            // Ported from updateBusinessParty's own identity-change branch: any
            // legal_name/vat_number/tin/company_registration_number change
            // returns the trust profile to PENDING_PROVIDER and records a
            // CounterpartyIdentityChanged event -- the party record can change,
            // but its prior trust evidence no longer speaks to the new
            // identity, so it must be earned again.
            if ($identityChanged && $trustProfile) {
                $fromStatus = $trustProfile->trust_status;
                $trustProfile->update([
                    'provider' => 'ITAS_BIPA', 'provider_environment' => 'CONTRACT_PENDING', 'trust_status' => 'PENDING_PROVIDER',
                    'tax_registration_status' => 'UNKNOWN',
                    'vat_verification_status' => $party['vat_number'] ? 'PENDING' : 'NOT_PROVIDED',
                    'tin_verification_status' => $party['tin'] ? 'PENDING' : 'NOT_PROVIDED',
                    'company_verification_status' => $party['company_registration_number'] ? 'PENDING' : 'NOT_PROVIDED',
                    'confidence_bps' => 0, 'evidence_hash' => null, 'source_reference' => null, 'reviewed_by' => null,
                    'checked_at' => null, 'expires_at' => null, 'updated_at' => $now,
                ]);
                CounterpartyTrustEvent::create([
                    'id' => (string) Str::uuid(), 'trust_profile_id' => $trustProfile->id, 'event_type' => 'CounterpartyIdentityChanged',
                    'from_status' => $fromStatus, 'to_status' => 'PENDING_PROVIDER', 'reason_code' => 'IDENTITY_CHANGE_REQUIRES_REVERIFICATION',
                    'evidence_hash' => null, 'actor_id' => $actor->id, 'occurred_at' => $now,
                ]);
                CommandLedger::outbox('COUNTERPARTY_TRUST', $trustProfile->id, 'CounterpartyVerificationRequested', $organisation->id, [
                    'business_party_id' => $id, 'trust_profile_id' => $trustProfile->id, 'status' => 'PENDING_PROVIDER',
                    'reason' => 'IDENTITY_CHANGED', 'correlation_id' => $correlationId,
                ], $now);
            }
            foreach (['CUSTOMER', 'SUPPLIER', 'SERVICE_PROVIDER'] as $relationship) {
                if (in_array($relationship, $party['relationships'], true)) {
                    // Mirrors the source's own ON CONFLICT upsert: reactivating an existing
                    // (organisation_id, party_id, relationship) row keeps its original
                    // effective_from if it was already active, only resetting it if the row
                    // was previously inactive (a genuinely new grant period).
                    $row = PartyRelationship::where('organisation_id', $organisation->id)->where('party_id', $id)->where('relationship', $relationship)->first();
                    if ($row) {
                        $row->update(['status' => 'ACTIVE', 'effective_from' => $row->status === 'ACTIVE' ? $row->effective_from : $now, 'effective_to' => null]);
                    } else {
                        PartyRelationship::create([
                            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $id,
                            'relationship' => $relationship, 'status' => 'ACTIVE', 'effective_from' => $now, 'effective_to' => null, 'created_at' => $now,
                        ]);
                    }
                } else {
                    PartyRelationship::where('organisation_id', $organisation->id)->where('party_id', $id)->where('relationship', $relationship)->where('status', 'ACTIVE')
                        ->update(['status' => 'INACTIVE', 'effective_to' => $now]);
                }
            }
            CommandLedger::record($actor->id, 'UPDATE_BUSINESS_PARTY', $idempotencyKey, $requestHash, 'BUSINESS_PARTY', $id, $now);
            CommandLedger::outbox('BUSINESS_PARTY', $id, 'BusinessPartyUpdated', $organisation->id, [
                'party_id' => $id, 'organisation_id' => $organisation->id, 'relationships' => $party['relationships'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'BUSINESS_PARTY_UPDATED', 'BUSINESS_PARTY', $id, [
                'organisationId' => $organisation->id, 'relationships' => $party['relationships'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function deactivate(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $deactivation = BusinessValidator::partyDeactivation($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'party_id' => $id, 'deactivation' => $deactivation]);
        $prior = CommandLedger::prior($actor->id, 'DEACTIVATE_BUSINESS_PARTY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->first();
        if (! $existing) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }
        if ($existing->status !== 'ACTIVE') {
            throw new RepositoryConflictException('Business party is already inactive.');
        }

        $now = now();

        DB::transaction(function () use ($deactivation, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->update(['status' => 'INACTIVE', 'updated_at' => $now]);
            PartyRelationship::where('party_id', $id)->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->update(['status' => 'INACTIVE', 'effective_to' => $now]);
            CommandLedger::record($actor->id, 'DEACTIVATE_BUSINESS_PARTY', $idempotencyKey, $requestHash, 'BUSINESS_PARTY', $id, $now);
            CommandLedger::outbox('BUSINESS_PARTY', $id, 'BusinessPartyDeactivated', $organisation->id, [
                'party_id' => $id, 'organisation_id' => $organisation->id, 'records_preserved' => true, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'BUSINESS_PARTY_DEACTIVATED', 'BUSINESS_PARTY', $id, [
                'organisationId' => $organisation->id, 'reason' => $deactivation['reason'], 'correlationId' => $correlationId, 'recordsPreserved' => true,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array{organisation_id: string, parties: list<array<string, mixed>>, total_count: int, limit: int, offset: int} */
    public function search(User $actor, ?string $requestedOrganisationId, array $params): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $query = BusinessValidator::partySearchQuery($params);

        $builder = BusinessParty::where('organisation_id', $organisation->id);
        if ($query['status']) {
            $builder->where('status', $query['status']);
        }
        if ($query['relationship']) {
            $builder->whereExists(function ($sub) use ($query) {
                $sub->select(DB::raw(1))->from('party_relationships as r2')
                    ->whereColumn('r2.party_id', 'business_parties.id')
                    ->where('r2.relationship', $query['relationship'])
                    ->where('r2.status', 'ACTIVE');
            });
        }
        if ($query['q']) {
            $like = '%'.$query['q'].'%';
            $builder->where(function ($sub) use ($like) {
                $sub->where('display_name', 'like', $like)->orWhere('legal_name', 'like', $like)
                    ->orWhere('vat_number', 'like', $like)->orWhere('tin', 'like', $like);
            });
        }

        $totalCount = (clone $builder)->count();
        $parties = $builder->orderBy('display_name')->limit($query['limit'])->offset($query['offset'])->get();
        // Red-team punch list #12 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
        // 2026-09-15.md): present() below used to run its own
        // PartyRelationship query every time it was called -- fine for
        // findOrFail()'s single-record case, but this loop called it once
        // per row, N+1, on every page of this list (and on every page
        // that embeds it, e.g. OperationsViewController's supplier
        // filter). A synthetic load seed (2026-09-15) made this
        // concretely visible for the first time. Batching into one
        // whereIn() query, grouped by party_id, collapses it back to a
        // fixed, small number of queries regardless of row count.
        $partyIds = $parties->pluck('id');
        $relationshipsByParty = $partyIds->isEmpty() ? collect() : PartyRelationship::whereIn('party_id', $partyIds)
            ->where('status', 'ACTIVE')->orderBy('relationship')->get()->groupBy('party_id');
        // Same N+1 fix as relationshipsByParty above, now for trust profiles too.
        $trustByParty = $partyIds->isEmpty() ? collect() : CounterpartyTrustProfile::whereIn('business_party_id', $partyIds)
            ->get()->keyBy('business_party_id');
        $presented = $parties
            ->map(fn (BusinessParty $party) => $this->present(
                $party,
                ($relationshipsByParty->get($party->id) ?? collect())->pluck('relationship')->values()->all(),
                // Not `?: false`: a party genuinely without a trust profile
                // must present as the resolved `null`, not fall through to
                // present()'s own "not batched, look it up" sentinel -- that
                // bug reintroduced exactly the N+1 this batching exists to
                // avoid (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_2026-09-15.md's
                // #12), for every party this batch didn't find a profile for.
                $trustByParty->get($party->id),
            ))
            ->values()->all();

        return ['organisation_id' => $organisation->id, 'parties' => $presented, 'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id, string $organisationId): array
    {
        $party = BusinessParty::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $party) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }

        return $this->present($party);
    }

    /**
     * `$relationships`, when given, must already be ACTIVE-only and
     * ordered by relationship name (search()'s own batched query does
     * this) -- omitted (the findOrFail() single-record case), this runs
     * that same query itself, scoped to just this one party.
     *
     * `$trust` follows the same already-batched-or-not convention as
     * `$relationships`: `false` (the default) means "not batched by the
     * caller, look it up here" (findOrFail()'s single-record case);
     * `null` or a CounterpartyTrustProfile means the caller (search())
     * already resolved it, including the "no profile" case, so this
     * should not query again. A party created before this feature (there
     * should be none in practice -- every create() call writes one in the
     * same transaction) presents as null fields rather than throwing,
     * matching the source's own LEFT JOIN counterparty_trust_profiles.
     *
     * @param  ?list<string>  $relationships
     * @return array<string, mixed>
     */
    private function present(BusinessParty $party, ?array $relationships = null, CounterpartyTrustProfile|null|false $trust = false): array
    {
        if ($trust === false) {
            $trust = CounterpartyTrustProfile::where('business_party_id', $party->id)->first();
        }

        return [
            'id' => $party->id, 'organisation_id' => $party->organisation_id, 'display_name' => $party->display_name,
            'legal_name' => $party->legal_name, 'vat_number' => $party->vat_number, 'tin' => $party->tin,
            'company_registration_number' => $party->company_registration_number,
            'email' => $party->email, 'phone' => $party->phone, 'address' => $party->address, 'status' => $party->status,
            'trust_status' => $trust?->trust_status, 'tax_registration_status' => $trust?->tax_registration_status,
            'confidence_bps' => $trust?->confidence_bps, 'provider_environment' => $trust?->provider_environment,
            'checked_at' => optional($trust?->checked_at)->toISOString(), 'expires_at' => optional($trust?->expires_at)->toISOString(),
            // Explicit orderBy: without one, MySQL's row order for this
            // unindexed-on-relationship read is unspecified (the source's
            // own GROUP_CONCAT carries the same lack of a guarantee), which
            // surfaced as a genuinely flaky ['CUSTOMER','SUPPLIER'] vs.
            // ['SUPPLIER','CUSTOMER'] assertion under the full test suite's
            // differently-shaped query plans -- alphabetical is stable and
            // matches this file's own present() ordering conventions
            // elsewhere.
            'relationships' => $relationships ?? PartyRelationship::where('party_id', $party->id)->where('status', 'ACTIVE')->orderBy('relationship')->pluck('relationship')->values()->all(),
            'created_at' => optional($party->created_at)->toISOString(), 'updated_at' => optional($party->updated_at)->toISOString(),
        ];
    }

    private function assertIdentifiersAvailable(string $organisationId, array $party, ?string $excludedId = null): void
    {
        if (! $party['vat_number'] && ! $party['tin'] && ! $party['company_registration_number']) {
            return;
        }
        $duplicate = BusinessParty::where('organisation_id', $organisationId)->where('status', 'ACTIVE')
            ->when($excludedId, fn ($q) => $q->where('id', '<>', $excludedId))
            ->where(function ($q) use ($party) {
                $q->when($party['vat_number'], fn ($qq) => $qq->orWhere('vat_number', $party['vat_number']))
                    ->when($party['tin'], fn ($qq) => $qq->orWhere('tin', $party['tin']))
                    ->when($party['company_registration_number'], fn ($qq) => $qq->orWhere('company_registration_number', $party['company_registration_number']));
            })
            ->first();
        if ($duplicate) {
            throw new RepositoryConflictException("An active business party already uses that VAT number or TIN ({$duplicate->display_name}, {$duplicate->id}).");
        }
    }
}
