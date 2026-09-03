<?php

namespace App\Services\Business;

use App\Domain\Business\BusinessValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\BusinessParty;
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
        $now = now();

        DB::transaction(function () use ($party, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            BusinessParty::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'display_name' => $party['display_name'],
                'legal_name' => $party['legal_name'], 'vat_number' => $party['vat_number'], 'tin' => $party['tin'],
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
            CommandLedger::record($actor->id, 'CREATE_BUSINESS_PARTY', $idempotencyKey, $requestHash, 'BUSINESS_PARTY', $id, $now);
            CommandLedger::outbox('BUSINESS_PARTY', $id, 'BusinessPartyCreated', $organisation->id, [
                'party_id' => $id, 'organisation_id' => $organisation->id, 'relationships' => $party['relationships'], 'correlation_id' => $correlationId,
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

        $now = now();

        DB::transaction(function () use ($party, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            BusinessParty::where('id', $id)->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->update([
                'display_name' => $party['display_name'], 'legal_name' => $party['legal_name'], 'vat_number' => $party['vat_number'],
                'tin' => $party['tin'], 'email' => $party['email'], 'phone' => $party['phone'], 'address' => $party['address'],
                'updated_at' => $now,
            ]);
            foreach (['CUSTOMER', 'SUPPLIER'] as $relationship) {
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
        $parties = $builder->orderBy('display_name')->limit($query['limit'])->offset($query['offset'])->get()
            ->map(fn (BusinessParty $party) => $this->present($party))->values()->all();

        return ['organisation_id' => $organisation->id, 'parties' => $parties, 'total_count' => $totalCount, 'limit' => $query['limit'], 'offset' => $query['offset']];
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

    /** @return array<string, mixed> */
    private function present(BusinessParty $party): array
    {
        return [
            'id' => $party->id, 'organisation_id' => $party->organisation_id, 'display_name' => $party->display_name,
            'legal_name' => $party->legal_name, 'vat_number' => $party->vat_number, 'tin' => $party->tin,
            'email' => $party->email, 'phone' => $party->phone, 'address' => $party->address, 'status' => $party->status,
            // Explicit orderBy: without one, MySQL's row order for this
            // unindexed-on-relationship read is unspecified (the source's
            // own GROUP_CONCAT carries the same lack of a guarantee), which
            // surfaced as a genuinely flaky ['CUSTOMER','SUPPLIER'] vs.
            // ['SUPPLIER','CUSTOMER'] assertion under the full test suite's
            // differently-shaped query plans -- alphabetical is stable and
            // matches this file's own present() ordering conventions
            // elsewhere.
            'relationships' => PartyRelationship::where('party_id', $party->id)->where('status', 'ACTIVE')->orderBy('relationship')->pluck('relationship')->values()->all(),
            'created_at' => optional($party->created_at)->toISOString(), 'updated_at' => optional($party->updated_at)->toISOString(),
        ];
    }

    private function assertIdentifiersAvailable(string $organisationId, array $party, ?string $excludedId = null): void
    {
        if (! $party['vat_number'] && ! $party['tin']) {
            return;
        }
        $duplicate = BusinessParty::where('organisation_id', $organisationId)->where('status', 'ACTIVE')
            ->when($excludedId, fn ($q) => $q->where('id', '<>', $excludedId))
            ->where(function ($q) use ($party) {
                $q->when($party['vat_number'], fn ($qq) => $qq->orWhere('vat_number', $party['vat_number']))
                    ->when($party['tin'], fn ($qq) => $qq->orWhere('tin', $party['tin']));
            })
            ->first();
        if ($duplicate) {
            throw new RepositoryConflictException("An active business party already uses that VAT number or TIN ({$duplicate->display_name}, {$duplicate->id}).");
        }
    }
}
