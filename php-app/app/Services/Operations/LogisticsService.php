<?php

namespace App\Services\Operations;

use App\Domain\Operations\LogisticsValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\FixedAsset;
use App\Models\LogisticsDelivery;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TaxpayerScope;
use App\Support\Business\CommandLedger;
use App\Support\Business\OrganisationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/logistics-repository.ts -- createLogisticsDelivery/
 * dispatchLogisticsDelivery/deliverLogisticsDelivery/
 * cancelLogisticsDelivery/getLogisticsDelivery/listLogisticsDeliveries. A
 * delivery always references the tax invoice (or POS sale) it fulfils; it
 * never invents its own separate sale record.
 */
class LogisticsService
{
    /** Ported verbatim from the source's own LOGISTICS_ACTION_EVENT_TYPE map. */
    private const ACTION_EVENT_TYPE = [
        'DISPATCH' => 'LogisticsDeliveryDispatched',
        'DELIVER' => 'LogisticsDeliveryDelivered',
        'CANCEL' => 'LogisticsDeliveryCancelled',
    ];

    public function __construct(private readonly OrganisationResolver $organisations) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId, ?string $requestedOrganisationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = LogisticsValidator::creation($payload);
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);
        $requestHash = CommandLedger::requestHash(['organisation_id' => $organisation->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'CREATE_LOGISTICS_DELIVERY', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $organisation->id);
        }
        $existing = LogisticsDelivery::where('organisation_id', $organisation->id)->where('delivery_number', $input['delivery_number'])->first();
        if ($existing) {
            throw new RepositoryConflictException("A delivery numbered {$input['delivery_number']} already exists as {$existing->id}.");
        }
        if ($input['vehicle_asset_id']) {
            $vehicle = FixedAsset::where('id', $input['vehicle_asset_id'])->where('organisation_id', $organisation->id)->where('asset_class', 'MOVABLE')->first();
            if (! $vehicle) {
                throw new BusinessResourceException('vehicle_asset_id must be a movable asset registered in the authorised organisation.');
            }
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $organisation, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            LogisticsDelivery::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'delivery_number' => $input['delivery_number'],
                'reference_type' => $input['reference_type'], 'reference_id' => $input['reference_id'], 'origin' => $input['origin'],
                'destination' => $input['destination'], 'vehicle_asset_id' => $input['vehicle_asset_id'], 'status' => 'PENDING',
                'notes' => $input['notes'], 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'CREATE_LOGISTICS_DELIVERY', $idempotencyKey, $requestHash, 'LOGISTICS_DELIVERY', $id, $now);
            CommandLedger::outbox('LOGISTICS_DELIVERY', $id, 'LogisticsDeliveryCreated', $organisation->id, [
                'delivery_number' => $input['delivery_number'], 'reference_type' => $input['reference_type'], 'reference_id' => $input['reference_id'],
                'organisation_id' => $organisation->id, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'LOGISTICS_DELIVERY_CREATED', 'LOGISTICS_DELIVERY', $id, [
                'deliveryNumber' => $input['delivery_number'], 'referenceType' => $input['reference_type'], 'organisationId' => $organisation->id, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->findOrFail($id, $organisation->id);
    }

    /** @return array<string, mixed> */
    public function dispatch(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        return $this->transition($id, 'DISPATCH', $actor, $idempotencyKey, $correlationId, [], ['dispatched_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function deliver(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        return $this->transition($id, 'DELIVER', $actor, $idempotencyKey, $correlationId, [], ['delivered_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function cancel(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        $input = LogisticsValidator::cancellation($payload);

        return $this->transition($id, 'CANCEL', $actor, $idempotencyKey, $correlationId, ['reason' => $input['reason']], [
            'cancelled_at' => now(), 'cancellation_reason' => $input['reason'],
        ]);
    }

    /** @return array<string, mixed> */
    public function get(string $id, User $actor): array
    {
        return $this->loadForActor($actor, $id)->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function list(User $actor, ?string $requestedOrganisationId): array
    {
        if (TaxpayerScope::isNational($actor)) {
            return LogisticsDelivery::orderByDesc('created_at')->get()->map(fn (LogisticsDelivery $delivery) => $delivery->toArray())->all();
        }
        $organisation = $this->organisations->resolve($actor, $requestedOrganisationId);

        return LogisticsDelivery::where('organisation_id', $organisation->id)->orderByDesc('created_at')->get()->map(fn (LogisticsDelivery $delivery) => $delivery->toArray())->all();
    }

    // -- internals --

    /** @return array<string, mixed> */
    private function transition(string $id, string $action, User $actor, string $idempotencyKey, string $correlationId, array $extraDetails, array $columnUpdates): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $delivery = $this->loadForActor($actor, $id);
        $target = LogisticsValidator::assertTransition($action, $delivery->status);
        $requestHash = CommandLedger::requestHash(['delivery_id' => $id, 'action' => $action, 'extra_details' => $extraDetails]);
        $command = "{$action}_LOGISTICS_DELIVERY";
        $prior = CommandLedger::prior($actor->id, $command, $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->findOrFail($prior, $delivery->organisation_id);
        }

        $now = now();
        $fromStatus = $delivery->status;
        DB::transaction(function () use ($target, $columnUpdates, $now, $actor, $id, $idempotencyKey, $requestHash, $correlationId, $extraDetails, $action, $fromStatus, $command, $delivery) {
            $updated = LogisticsDelivery::where('id', $id)->where('status', $fromStatus)->update([...$columnUpdates, 'status' => $target, 'updated_at' => $now]);
            if ($updated === 0) {
                // Resilience to User Errors pass (2026-09-14): a genuine
                // concurrent transition raced this one and won between
                // loadForActor()'s read and this guarded UPDATE -- refuse
                // before recording a command/audit trail that would claim
                // this transition happened when it did not.
                throw new RepositoryConflictException("Logistics delivery {$id} was changed by another action; reload and try again.");
            }
            CommandLedger::record($actor->id, $command, $idempotencyKey, $requestHash, 'LOGISTICS_DELIVERY', $id, $now);
            CommandLedger::outbox('LOGISTICS_DELIVERY', $id, self::ACTION_EVENT_TYPE[$action], $delivery->organisation_id, [
                'logistics_delivery_id' => $id, 'from_status' => $fromStatus, 'to_status' => $target, 'correlation_id' => $correlationId, ...$extraDetails,
            ], $now);
            AuditService::append($actor, "LOGISTICS_DELIVERY_{$action}ED", 'LOGISTICS_DELIVERY', $id, [
                'fromStatus' => $fromStatus, 'toStatus' => $target, 'correlationId' => $correlationId, ...$extraDetails,
            ], $now);
        });

        return $this->findOrFail($id, $delivery->organisation_id);
    }

    private function loadForActor(User $actor, string $id): LogisticsDelivery
    {
        $delivery = LogisticsDelivery::where('id', $id)->first();
        if (! $delivery) {
            throw new BusinessResourceException('Logistics delivery was not found.', 404);
        }
        TaxpayerScope::requireTaxpayer($actor, $delivery->organisation->taxpayer_id);

        return $delivery;
    }

    /** @return array<string, mixed> */
    private function findOrFail(string $id, string $organisationId): array
    {
        $delivery = LogisticsDelivery::where('id', $id)->where('organisation_id', $organisationId)->first();
        if (! $delivery) {
            throw new BusinessResourceException('Logistics delivery was not found in the authorised organisation.', 404);
        }

        return $delivery->toArray();
    }
}
