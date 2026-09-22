<?php

namespace App\Services\Developer;

use App\Domain\Developer\DeveloperConformanceEvaluator;
use App\Exceptions\BusinessResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\ApiClient;
use App\Models\CredentialRef;
use App\Models\Organisation;
use App\Models\TestRun;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Business\CommandLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/developer-repository.ts's rotateCredential/
 * runConformance -- Module 10 Phase D. CreateClient/RevokeCredential are
 * not ported here: this port's own App\Services\Integration\
 * PosApiClientService (a prior, user-requested feature reaching these
 * same `api_clients`/`credential_refs` tables for POS invoice-submission
 * credentials specifically) already issues and revokes `api_clients` rows,
 * so RotateCredential/RunConformance are written to operate on any
 * `api_clients` row an actor's organisation owns regardless of which
 * command created it, rather than duplicating a second, more generic
 * client-creation path this environment does not otherwise need.
 *
 * Source gates both commands on `developer:manage` (operationClass
 * BUSINESS_WRITE, not COMPLIANCE_WRITE -- no `step-up` requirement here,
 * unlike Reconciliation's Assign/Resolve), distinct from
 * PosApiClientService's own `integrations:manage` + SELLER-capability
 * gate -- a deliberate, documented divergence in this port: the same
 * underlying table now has two independently-gated write surfaces,
 * matching how source itself treats CreateClient (Developer Portal,
 * arbitrary scopes) and this port's POS-credential issuance (hard-coded
 * `invoices:submit` scope) as genuinely different commands.
 *
 * Honest, discoverable consequence of that same divergence:
 * DeveloperConformanceEvaluator's SCOPES_DECLARED check enforces source's
 * own dot-separated `resource.action` scope pattern, but
 * PosApiClientService::SCOPE_INVOICE_SUBMIT ('invoices:submit') uses this
 * port's own colon-separated permission-code convention instead (load-
 * bearing: App\Http\Middleware\AuthenticatePosApiClient checks that exact
 * string, so it cannot be reformatted to satisfy the pattern without
 * breaking real POS authentication). A POS-issued credential therefore
 * always fails SCOPES_DECLARED under RunConformance -- ported faithfully
 * rather than loosened, since weakening the pattern would silently accept
 * scope strings source's own validateClientCreation would reject.
 */
class DeveloperPlatformService
{
    public function rotateCredential(Organisation $organisation, string $apiClientId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $client = $this->loadClient($organisation, $apiClientId);
        if ($client->status === 'REVOKED') {
            throw new RepositoryConflictException("A revoked API client's credential cannot be rotated.");
        }

        $requestHash = CommandLedger::requestHash(['api_client_id' => $apiClientId, 'action' => 'ROTATE']);
        $prior = CommandLedger::prior($actor->id, 'ROTATE_CREDENTIAL', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentClient(ApiClient::findOrFail($prior));
        }

        $now = now();
        $newReference = "secret-manager://pending/{$apiClientId}/".Str::random(8);

        DB::transaction(function () use ($client, $actor, $now, $newReference, $idempotencyKey, $requestHash, $correlationId) {
            CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->update(['status' => 'ROTATED']);
            CredentialRef::create([
                'id' => (string) Str::uuid(), 'api_client_id' => $client->id, 'credential_reference' => $newReference,
                'status' => 'ACTIVE', 'issued_by' => $actor->id, 'issued_at' => $now,
            ]);
            $client->update(['credential_reference' => $newReference, 'last_rotated_at' => $now]);

            CommandLedger::record($actor->id, 'ROTATE_CREDENTIAL', $idempotencyKey, $requestHash, 'API_CLIENT', $client->id, $now);
            CommandLedger::outbox('API_CLIENT', $client->id, 'ApiClientCredentialRotated', $client->organisation_id, [
                'api_client_id' => $client->id, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CREDENTIAL_ROTATED', 'API_CLIENT', $client->id, ['correlationId' => $correlationId], $now);
        });

        return $this->presentClient($client->refresh());
    }

    public function runConformance(Organisation $organisation, string $apiClientId, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $client = $this->loadClient($organisation, $apiClientId);

        $requestHash = CommandLedger::requestHash(['api_client_id' => $apiClientId, 'action' => 'RUN_CONFORMANCE']);
        $prior = CommandLedger::prior($actor->id, 'RUN_CONFORMANCE', $idempotencyKey, $requestHash);
        if ($prior !== null) {
            return $this->presentTestRun(TestRun::findOrFail($prior));
        }

        $currentCredential = CredentialRef::where('api_client_id', $client->id)->orderByDesc('issued_at')->first();
        $checks = DeveloperConformanceEvaluator::evaluate([
            'scopes' => $client->scopeList(),
            'rate_limit_profile' => $client->rate_limit_profile,
            'client_status' => $client->status,
            'current_credential_status' => $currentCredential?->status,
        ]);
        $outcome = DeveloperConformanceEvaluator::outcome($checks);

        $runId = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($client, $actor, $now, $runId, $checks, $outcome, $idempotencyKey, $requestHash, $correlationId) {
            TestRun::create([
                'id' => $runId, 'api_client_id' => $client->id, 'checks' => json_encode($checks),
                'outcome' => $outcome, 'run_by' => $actor->id, 'run_at' => $now,
            ]);
            CommandLedger::record($actor->id, 'RUN_CONFORMANCE', $idempotencyKey, $requestHash, 'TEST_RUN', $runId, $now);
            CommandLedger::outbox('API_CLIENT', $client->id, $outcome === 'PASSED' ? 'ApiClientConformancePassed' : 'ApiClientConformanceFailed', $client->organisation_id, [
                'api_client_id' => $client->id, 'test_run_id' => $runId, 'outcome' => $outcome,
                'test_suite_version' => DeveloperConformanceEvaluator::TEST_SUITE_VERSION, 'correlation_id' => $correlationId,
            ], $now);
            AuditService::append($actor, 'API_CLIENT_CONFORMANCE_RUN', 'API_CLIENT', $client->id, [
                'testRunId' => $runId, 'outcome' => $outcome, 'correlationId' => $correlationId,
            ], $now);
        });

        return $this->presentTestRun(TestRun::findOrFail($runId));
    }

    private function loadClient(Organisation $organisation, string $apiClientId): ApiClient
    {
        $client = ApiClient::where('id', $apiClientId)->where('organisation_id', $organisation->id)->first();
        if (! $client) {
            throw new BusinessResourceException('API client was not found in your organisation.', 404);
        }

        return $client;
    }

    /** @return array<string, mixed> */
    private function presentClient(ApiClient $client): array
    {
        return [
            'id' => $client->id, 'name' => $client->name, 'client_key' => $client->client_key,
            'scopes' => $client->scopeList(), 'status' => $client->status, 'rate_limit_profile' => $client->rate_limit_profile,
            'last_rotated_at' => $client->last_rotated_at?->toIso8601String(), 'expires_at' => $client->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentTestRun(TestRun $run): array
    {
        return [
            'id' => $run->id, 'api_client_id' => $run->api_client_id, 'checks' => $run->checkList(),
            'outcome' => $run->outcome, 'run_by' => $run->run_by, 'run_at' => $run->run_at?->toIso8601String(),
        ];
    }
}
