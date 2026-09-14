<?php

namespace App\Services\Operations;

use App\Domain\Operations\FixedAssetValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/fixed-asset-repository.ts -- registerFixedAsset/
 * recordFixedAssetValuation/flagFixedAssetMaintenance/restoreFixedAsset/
 * disposeFixedAsset/getFixedAsset/listFixedAssets. Serves both the
 * Immovable and Movable Asset Management pages: one table/service, filtered
 * by asset_class, exactly as the source's own repository is shared.
 */
class FixedAssetService
{
    /** Ported verbatim from the source's own FIXED_ASSET_ACTION_EVENT_TYPE map. */
    private const ACTION_EVENT_TYPE = [
        'FLAG_MAINTENANCE' => 'FixedAssetFlaggedForMaintenance',
        'RESTORE' => 'FixedAssetRestored',
        'DISPOSE' => 'FixedAssetDisposed',
    ];

    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function register(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = FixedAssetValidator::registration($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'REGISTER_FIXED_ASSET', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = FixedAsset::where('organisation_id', $organisation->id)->where('asset_code', $input['asset_code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("An asset with code {$input['asset_code']} already exists as {$existing->id}.");
        }
        if ($input['custodian_employee_id']) {
            $employee = Employee::where('id', $input['custodian_employee_id'])->where('organisation_id', $organisation->id)->first();
            if (! $employee) {
                throw new BusinessResourceException('custodian_employee_id does not exist in the authorised organisation.');
            }
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            FixedAsset::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'asset_class' => $input['asset_class'], 'asset_code' => $input['asset_code'],
                'category' => $input['category'], 'description' => $input['description'],
                'serial_or_registration_number' => $input['serial_or_registration_number'], 'location_or_address' => $input['location_or_address'],
                'custodian_employee_id' => $input['custodian_employee_id'], 'acquisition_date' => $input['acquisition_date'],
                'acquisition_cost_cents' => $input['acquisition_cost_cents'], 'current_value_cents' => $input['current_value_cents'],
                'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'REGISTER_FIXED_ASSET', $idempotencyKey, $requestHash, 'FIXED_ASSET', $id, $now);
            CommandLedger::outbox('FIXED_ASSET', $id, 'FixedAssetRegistered', $organisation->id, [
                'asset_class' => $input['asset_class'], 'asset_code' => $input['asset_code'], 'organisation_id' => $organisation->id, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'FIXED_ASSET_REGISTERED', 'FIXED_ASSET', $id, [
                'assetClass' => $input['asset_class'], 'assetCode' => $input['asset_code'], 'organisationId' => $organisation->id, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function recordValuation(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = FixedAssetValidator::valuation($payload);
        $asset = $this->loadForActor($actor, $id);
        if ($asset->status === 'DISPOSED') {
            throw new RepositoryConflictException('A disposed asset can no longer be revalued.');
        }
        $requestHash = CommandLedger::requestHash(['asset_id' => $id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RECORD_FIXED_ASSET_VALUATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $asset->organisation_id);
        }

        $now = now();
        DB::transaction(function () use ($input, $asset, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            FixedAsset::where('id', $id)->update(['current_value_cents' => $input['current_value_cents'], 'updated_at' => $now]);
            CommandLedger::record($actor->id, 'RECORD_FIXED_ASSET_VALUATION', $idempotencyKey, $requestHash, 'FIXED_ASSET', $id, $now);
            CommandLedger::outbox('FIXED_ASSET', $id, 'FixedAssetValuationRecorded', $asset->organisation_id, [
                'fixed_asset_id' => $id, 'current_value_cents' => $input['current_value_cents'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'FIXED_ASSET_VALUATION_RECORDED', 'FIXED_ASSET', $id, [
                'currentValueCents' => $input['current_value_cents'], 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $asset->organisation_id);
    }

    /** @return array<string, mixed> */
    public function flagMaintenance(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        return $this->transition($id, 'FLAG_MAINTENANCE', $actor, $idempotencyKey, $correlationId, [], []);
    }

    /** @return array<string, mixed> */
    public function restore(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        return $this->transition($id, 'RESTORE', $actor, $idempotencyKey, $correlationId, [], []);
    }

    /** @return array<string, mixed> */
    public function dispose(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        $input = FixedAssetValidator::disposal($payload);
        $now = now();

        return $this->transition($id, 'DISPOSE', $actor, $idempotencyKey, $correlationId, ['reason' => $input['reason']], [
            'disposal_reason' => $input['reason'], 'disposed_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    public function get(string $id, User $actor): array
    {
        $asset = $this->loadForActor($actor, $id);

        return $asset->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function list(User $actor, ?string $assetClass, ?string $requestedOrganisationId): array
    {
        if (TenantScope::isNational($actor)) {
            $query = FixedAsset::query();
            if ($assetClass) {
                $query->where('asset_class', $assetClass);
            }

            return $query->orderByDesc('created_at')->get()->map(fn (FixedAsset $asset) => $asset->toArray())->all();
        }
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $query = FixedAsset::where('organisation_id', $organisation->id);
        if ($assetClass) {
            $query->where('asset_class', $assetClass);
        }

        return $query->orderByDesc('created_at')->get()->map(fn (FixedAsset $asset) => $asset->toArray())->all();
    }

    // -- internals --

    /** @return array<string, mixed> */
    private function transition(string $id, string $action, User $actor, string $idempotencyKey, string $correlationId, array $extraDetails, array $columnUpdates): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $asset = $this->loadForActor($actor, $id);
        $target = FixedAssetValidator::assertTransition($action, $asset->status);
        $requestHash = CommandLedger::requestHash(['asset_id' => $id, 'action' => $action, 'extra_details' => $extraDetails]);
        $command = "{$action}_FIXED_ASSET";
        $prior = CommandLedger::prior($actor->id, $command, $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $asset->organisation_id);
        }

        $now = now();
        $fromStatus = $asset->status;
        DB::transaction(function () use ($asset, $target, $columnUpdates, $now, $actor, $id, $idempotencyKey, $requestHash, $correlationId, $extraDetails, $action, $fromStatus, $command) {
            $updated = FixedAsset::where('id', $id)->where('status', $fromStatus)->update([...$columnUpdates, 'status' => $target, 'updated_at' => $now]);
            if ($updated === 0) {
                // Resilience to User Errors pass (2026-09-14): a genuine
                // concurrent transition (e.g. two action buttons clicked
                // from the same stale page) raced this one and won between
                // loadForActor()'s read and this guarded UPDATE -- refuse
                // before recording a command/audit trail that would claim
                // this transition happened when it did not.
                throw new RepositoryConflictException("Fixed asset {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, $command, $idempotencyKey, $requestHash, 'FIXED_ASSET', $id, $now);
            CommandLedger::outbox('FIXED_ASSET', $id, self::ACTION_EVENT_TYPE[$action], $asset->organisation_id, [
                'fixed_asset_id' => $id, 'from_status' => $fromStatus, 'to_status' => $target, 'correlation_id' => $correlationId, ...$extraDetails,
            ], $now);
            AuditService::append($actor, "FIXED_ASSET_{$action}D", 'FIXED_ASSET', $id, [
                'fromStatus' => $fromStatus, 'toStatus' => $target, 'correlationId' => $correlationId, ...$extraDetails,
            ], $now);
        });

        return $this->findOrFail($id, $asset->organisation_id);
    }

    private function loadForActor(User $actor, string $id): FixedAsset
    {
        $asset = FixedAsset::where('id', $id)->first();
        if (! $asset) {
            throw new BusinessResourceException('Fixed asset was not found.', 404);
        }
        TenantScope::requireTaxpayer($actor, $asset->organisation->taxpayer_id);

        return $asset;
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id, string $organisationId): array
    {
        $asset = FixedAsset::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $asset) {
            throw new BusinessResourceException('Fixed asset was not found in the authorised organisation.', 404);
        }

        return $asset->toArray();
    }
}
