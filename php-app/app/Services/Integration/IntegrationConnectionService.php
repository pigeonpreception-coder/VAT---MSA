<?php

namespace App\Services\Integration;

use App\Domain\Integration\IntegrationValidator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\IntegrationConnection;
use App\Models\Organisation;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/integration-repository.ts -- Module 10 Phase A:
 * RegisterIntegration/ApproveIntegration/SuspendIntegration/StartSync/
 * GetHealth for the generic, provider-agnostic connector model
 * (`integration_connections`/`sync_jobs`). Genuinely distinct from
 * App\Services\Integration\PosApiClientService, which shares this
 * namespace but manages a different aggregate entirely (taxpayer POS
 * invoice-submission credentials, `api_clients`/`credential_refs`).
 *
 * No Blade UI: source itself has no page.tsx for any of these five
 * commands -- GetHealth's own doc comment explicitly reasons that
 * connection discovery/listing is "already covered by the existing
 * GET /api/v1/platform snapshot's own `integrations` array -- deliberately
 * not duplicated here", and this port's own `/platform` route
 * (App\Http\Controllers\Platform\PlatformSnapshotController) and the
 * Super Administration portal's own summary tile already surface that
 * same read data. A dedicated register/approve/suspend/sync console is a
 * genuinely separate, larger UI feature source never built, not a gap in
 * this port specifically.
 */
class IntegrationConnectionService
{
    public function register(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = IntegrationValidator::registration($payload);
        $organisationId = $this->resolveOrganisationForRegistration($actor);

        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'REGISTER_INTEGRATION', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentConnection(IntegrationConnection::findOrFail($prior));
        }

        $existing = IntegrationConnection::where('provider_key', $input['provider_key'])
            ->where(fn ($q) => $organisationId === null ? $q->whereNull('organisation_id') : $q->where('organisation_id', $organisationId))
            ->first();
        if ($existing) {
            throw new RepositoryConflictException("A connection for {$input['provider_key']} already exists as {$existing->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        $connection = DB::transaction(function () use ($id, $input, $organisationId, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
            $connection = IntegrationConnection::create([
                'id' => $id, 'organisation_id' => $organisationId, 'provider_key' => $input['provider_key'],
                'category' => $input['category'], 'display_name' => $input['display_name'],
                'capabilities' => json_encode($input['capabilities']), 'endpoint_reference' => $input['endpoint_reference'],
                'credential_reference' => $input['credential_reference'], 'configuration_status' => 'DRAFT',
                'operational_status' => 'DISABLED', 'data_classification' => $input['data_classification'],
                'last_health_check_at' => null, 'last_health_outcome' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);

            CommandLedger::record($actor->id, 'REGISTER_INTEGRATION', $idempotencyKey, $requestHash, 'INTEGRATION_CONNECTION', $id, $now);
            CommandLedger::outbox('INTEGRATION_CONNECTION', $id, 'IntegrationRegistered', $organisationId ?? 'platform', [
                'provider_key' => $input['provider_key'], 'category' => $input['category'],
                'organisation_id' => $organisationId, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'INTEGRATION_REGISTERED', 'INTEGRATION_CONNECTION', $id, [
                'providerKey' => $input['provider_key'], 'category' => $input['category'],
                'organisationId' => $organisationId, 'correlationId' => $correlationId,
            ], $now);

            return $connection;
        });

        return $this->presentConnection($connection);
    }

    /** ApproveIntegration: DRAFT or SUSPENDED -> CONFIGURED (and operational_status -> OPERATIONAL). No maker-checker requirement -- source names no such rule here, unlike this port's own case/refund lifecycles. */
    public function approve(string $id, User $actor, string $idempotencyKey, string $correlationId): array
    {
        return $this->transition($id, 'APPROVE', $actor, $idempotencyKey, $correlationId, []);
    }

    /** SuspendIntegration: CONFIGURED -> SUSPENDED (and operational_status -> DISABLED). Requires a recorded reason. */
    public function suspend(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        $input = IntegrationValidator::suspension($payload);

        return $this->transition($id, 'SUSPEND', $actor, $idempotencyKey, $correlationId, ['reason' => $input['reason']]);
    }

    private function transition(string $id, string $action, User $actor, string $idempotencyKey, string $correlationId, array $extraDetails): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $connection = $this->loadConnectionForActor($actor, $id);
        $target = IntegrationValidator::assertTransition($action, $connection->configuration_status);

        $requestHash = CommandLedger::requestHash(['connection_id' => $id, 'action' => $action, 'extra_details' => $extraDetails]);
        $command = $action === 'APPROVE' ? 'APPROVE_INTEGRATION' : 'SUSPEND_INTEGRATION';
        $prior = CommandLedger::prior($actor->id, $command, $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentConnection(IntegrationConnection::findOrFail($prior));
        }

        $operationalStatus = $target === 'CONFIGURED' ? 'OPERATIONAL' : 'DISABLED';
        $now = now();
        $fromStatus = $connection->configuration_status;
        DB::transaction(function () use ($connection, $target, $operationalStatus, $now, $action, $command, $idempotencyKey, $requestHash, $correlationId, $extraDetails, $fromStatus, $actor) {
            $connection->update(['configuration_status' => $target, 'operational_status' => $operationalStatus, 'updated_at' => $now]);

            CommandLedger::record($actor->id, $command, $idempotencyKey, $requestHash, 'INTEGRATION_CONNECTION', $connection->id, $now);
            CommandLedger::outbox('INTEGRATION_CONNECTION', $connection->id, $action === 'APPROVE' ? 'IntegrationApproved' : 'IntegrationSuspended', $connection->organisation_id ?? 'platform', array_merge([
                'integration_connection_id' => $connection->id, 'from_status' => $fromStatus, 'to_status' => $target, 'correlation_id' => $correlationId,
            ], $extraDetails), $now);
            AuditService::append($actor, $action === 'APPROVE' ? 'INTEGRATION_APPROVED' : 'INTEGRATION_SUSPENDED', 'INTEGRATION_CONNECTION', $connection->id, array_merge([
                'fromStatus' => $fromStatus, 'toStatus' => $target, 'correlationId' => $correlationId,
            ], $extraDetails), $now);
        });

        return $this->presentConnection($connection->refresh());
    }

    /**
     * StartSync. This pilot has no live per-provider connector
     * implementation for any provider -- explicitly out of scope for the
     * generic connector model this phase builds. Every StartSync
     * therefore completes immediately, recording an honest FAILED
     * `sync_jobs` row with a typed reason, never a fabricated COMPLETED
     * with invented record counts. This still proves the command's full
     * shape (idempotent, audited, tenant/platform-scoped, only runnable
     * against an approved CONFIGURED connection) end to end.
     */
    public function startSync(string $id, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = IntegrationValidator::syncStart($payload);
        $connection = $this->loadConnectionForActor($actor, $id);
        if ($connection->configuration_status !== 'CONFIGURED') {
            throw new RepositoryConflictException('A sync can only be started for an approved, CONFIGURED connection.');
        }

        $requestHash = CommandLedger::requestHash(['connection_id' => $id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'START_SYNC', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentSyncJob(SyncJob::findOrFail($prior));
        }

        $jobId = (string) Str::uuid();
        $now = now();
        $noConnectorReason = 'No live connector implementation exists for this provider yet -- this pilot builds the typed StartSync command shape, not a working per-provider data pipe.';
        $job = DB::transaction(function () use ($jobId, $id, $connection, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $noConnectorReason) {
            $job = SyncJob::create([
                'id' => $jobId, 'integration_connection_id' => $id, 'organisation_id' => $connection->organisation_id,
                'job_type' => $input['job_type'], 'direction' => $input['direction'], 'status' => 'FAILED', 'cursor' => null,
                'records_read' => 0, 'records_written' => 0, 'error_count' => 1, 'requested_by' => $actor->id,
                'requested_at' => $now, 'started_at' => $now, 'completed_at' => $now, 'last_error' => $noConnectorReason,
            ]);

            CommandLedger::record($actor->id, 'START_SYNC', $idempotencyKey, $requestHash, 'SYNC_JOB', $jobId, $now);
            CommandLedger::outbox('SYNC_JOB', $jobId, 'SyncJobFailed', $connection->organisation_id ?? 'platform', [
                'integration_connection_id' => $id, 'job_type' => $input['job_type'], 'direction' => $input['direction'], 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'SYNC_STARTED', 'SYNC_JOB', $jobId, [
                'integrationConnectionId' => $id, 'jobType' => $input['job_type'], 'direction' => $input['direction'],
                'outcome' => 'FAILED_NO_CONNECTOR', 'correlationId' => $correlationId,
            ], $now);

            return $job;
        });

        return $this->presentSyncJob($job);
    }

    /**
     * GetHealth: the connection's own stored status plus its 10 most
     * recent sync attempts -- a real health projection, not a live probe
     * (there is nothing to live-probe; see startSync()'s own doc comment).
     *
     * @return array{connection: array<string, mixed>, recent_sync_jobs: list<array<string, mixed>>}
     */
    public function getHealth(string $id, User $actor): array
    {
        $connection = $this->loadConnectionForActor($actor, $id);
        $recentSyncJobs = SyncJob::where('integration_connection_id', $connection->id)
            ->orderByDesc('requested_at')->limit(10)->get();

        return [
            'connection' => $this->presentConnection($connection),
            'recent_sync_jobs' => $recentSyncJobs->map(fn (SyncJob $job) => $this->presentSyncJob($job))->values()->all(),
        ];
    }

    /**
     * Any actor with no taxpayer_id at all -- a national NamRA role or a
     * platform-technical role (SUPER_ADMIN/INFRASTRUCTURE_ADMIN, neither
     * of which App\Support\Access\TaxpayerScope::isNational() itself
     * recognises, since neither represents a tax-administration function)
     * -- registers a platform-wide connection (organisation_id NULL). Any
     * actor with a taxpayer_id registers for their own active
     * organisation only. Deliberately checks `taxpayer_id === null`
     * directly rather than calling TaxpayerScope::isNational(): that helper
     * additionally requires the role to be in its own national-role list,
     * which would wrongly deny SUPER_ADMIN/INFRASTRUCTURE_ADMIN a
     * platform-wide registration even though source's own rule for this
     * command is exactly "has no taxpayer of their own", nothing more.
     */
    private function resolveOrganisationForRegistration(User $actor): ?string
    {
        if ($actor->taxpayer_id === null) {
            return null;
        }
        $organisation = Organisation::where('taxpayer_id', $actor->taxpayer_id)->where('status', 'ACTIVE')->first();
        if (! $organisation) {
            throw new AuthorizationException('Your account is not assigned to an active organisation.');
        }

        return $organisation->id;
    }

    /**
     * Loads a connection and enforces its ownership boundary: a tenant
     * actor may only ever act on their own organisation's row (never a
     * platform-wide one), and a platform/national actor may only ever act
     * on a platform-wide row (never reach into a specific tenant's
     * connection) -- kept deliberately symmetric and simple, matching
     * source's own doc comment for this method exactly.
     */
    private function loadConnectionForActor(User $actor, string $id): IntegrationConnection
    {
        $connection = IntegrationConnection::find($id);
        if (! $connection) {
            throw new BusinessResourceException('Integration connection was not found.', 404);
        }
        if ($connection->organisation_id !== null) {
            if ($actor->taxpayer_id === null) {
                throw new AuthorizationException("Only that connection's own organisation may manage it.");
            }
            $owns = Organisation::where('taxpayer_id', $actor->taxpayer_id)->where('id', $connection->organisation_id)->exists();
            if (! $owns) {
                throw new AuthorizationException('This connection is outside your authorised organisation scope.');
            }
        } elseif ($actor->taxpayer_id !== null) {
            throw new AuthorizationException('Only a national or platform-scope actor may manage a platform-wide connection.');
        }

        return $connection;
    }

    /** @return array<string, mixed> */
    private function presentConnection(IntegrationConnection $connection): array
    {
        return $connection->toArray();
    }

    /** @return array<string, mixed> */
    private function presentSyncJob(SyncJob $job): array
    {
        return $job->toArray();
    }
}
