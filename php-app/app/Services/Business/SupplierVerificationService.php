<?php

namespace App\Services\Business;

use App\Exceptions\BusinessResourceException;
use App\Models\BusinessParty;
use App\Models\PartyRelationship;
use App\Models\PartyVerificationSnapshot;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use App\Support\Business\TransactionClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/business-repository.ts's verifySupplier/
 * getSupplierVerificationHistory (Module 5 Phase A) -- the one function
 * Phase 10 (accounting/commercial) deferred, now built. "Against Module 1's
 * taxpayer adapter, not a fresh identity concept" per the source's own
 * comment: this calls the same TransactionClassifier every invoice
 * certification already uses, rather than a second supplier-specific
 * lookup.
 */
class SupplierVerificationService
{
    public function __construct(private readonly OrganisationResolver $organisations) {}

    /**
     * Unlike most commands in this file, this always re-checks live and
     * writes a brand-new snapshot row rather than returning stored data on
     * a replayed key -- the same deliberate departure Module 4's
     * evaluateRisk took, since "was this supplier valid as of today" cannot
     * be answered from a cached result. The replay check here only
     * prevents a literal retry from writing a second redundant audit/
     * outbox pair.
     *
     * @return array<string, mixed>
     */
    public function verify(string $partyId, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $party = BusinessParty::where('id', $partyId)->where('organisation_id', $organisation->id)->first();
        if (! $party) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }
        $relationship = PartyRelationship::where('party_id', $partyId)->where('organisation_id', $organisation->id)
            ->where('relationship', 'SUPPLIER')->where('status', 'ACTIVE')->first();
        if (! $relationship) {
            throw new BusinessResourceException('This business party is not an active supplier.', 409);
        }
        if (! $party->vat_number) {
            throw new BusinessResourceException('This supplier has no VAT number recorded to verify against the national taxpayer register.', 409);
        }

        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'party_id' => $partyId, 'vat_number' => $party->vat_number]);
        $prior = CommandLedger::prior($actor->id, 'VERIFY_SUPPLIER', $idempotencyKey, $requestHash);
        $classification = TransactionClassifier::classify($party->vat_number);

        $id = (string) Str::uuid();
        $now = now();

        DB::transaction(function () use ($organisation, $partyId, $classification, $actor, $id, $now, $prior, $idempotencyKey, $requestHash, $correlationId) {
            PartyVerificationSnapshot::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'party_id' => $partyId, 'vat_number' => $classification['vat_number'],
                'taxpayer_active' => $classification['taxpayer_active'], 'organisation_active' => $classification['organisation_active'],
                'can_act_as_seller' => $classification['can_act_as_seller'], 'capabilities' => AuditService::canonicalJson($classification['capabilities']),
                'verified_by' => $actor->id, 'verified_at' => $now,
            ]);
            if (! $prior) {
                CommandLedger::record($actor->id, 'VERIFY_SUPPLIER', $idempotencyKey, $requestHash, 'PARTY_VERIFICATION_SNAPSHOT', $id, $now);
                CommandLedger::outbox('PARTY_VERIFICATION_SNAPSHOT', $id, 'SupplierVerified', $organisation->id, [
                    'snapshot_id' => $id, 'party_id' => $partyId, 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId,
                ], $now);
                AuditService::append($actor, 'SUPPLIER_VERIFIED', 'BUSINESS_PARTY', $partyId, [
                    'organisationId' => $organisation->id, 'vatNumber' => $classification['vat_number'],
                    'taxpayerActive' => $classification['taxpayer_active'], 'canActAsSeller' => $classification['can_act_as_seller'], 'correlationId' => $correlationId,
                ], $now);
            }
        });

        return $this->presentSnapshot(PartyVerificationSnapshot::findOrFail($id));
    }

    /** @return array{party: array<string, mixed>, snapshots: list<array<string, mixed>>} */
    public function history(string $partyId, User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $party = BusinessParty::where('id', $partyId)->where('organisation_id', $organisation->id)->first();
        if (! $party) {
            throw new BusinessResourceException('Business party was not found in the authorised organisation.', 404);
        }
        $snapshots = PartyVerificationSnapshot::where('party_id', $partyId)->where('organisation_id', $organisation->id)
            ->orderByDesc('verified_at')->get()->map(fn (PartyVerificationSnapshot $s) => $this->presentSnapshot($s))->values()->all();

        return ['party' => $this->presentParty($party), 'snapshots' => $snapshots];
    }

    /** @return array<string, mixed> */
    private function presentSnapshot(PartyVerificationSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id, 'organisation_id' => $snapshot->organisation_id, 'party_id' => $snapshot->party_id,
            'vat_number' => $snapshot->vat_number, 'taxpayer_active' => (bool) $snapshot->taxpayer_active,
            'organisation_active' => (bool) $snapshot->organisation_active, 'can_act_as_seller' => (bool) $snapshot->can_act_as_seller,
            'capabilities' => json_decode($snapshot->capabilities, true) ?? [], 'verified_by' => $snapshot->verified_by,
            'verified_at' => optional($snapshot->verified_at)->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentParty(BusinessParty $party): array
    {
        return [
            'id' => $party->id, 'organisation_id' => $party->organisation_id, 'display_name' => $party->display_name,
            'legal_name' => $party->legal_name, 'vat_number' => $party->vat_number, 'tin' => $party->tin,
            'email' => $party->email, 'phone' => $party->phone, 'address' => $party->address, 'status' => $party->status,
            'relationships' => PartyRelationship::where('party_id', $party->id)->where('status', 'ACTIVE')->pluck('relationship')->values()->all(),
            'created_at' => optional($party->created_at)->toISOString(), 'updated_at' => optional($party->updated_at)->toISOString(),
        ];
    }
}
